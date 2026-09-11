<?php

namespace App\Http\Controllers;

use App\Support\VariacaoPreco;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

class ReportController extends Controller
{
    /**
     * Colunas de cada relatorio: titulo, largura no Excel, tipo e campo.
     *
     * O tipo decide a formatacao nos dois formatos de saida — preco com duas
     * casas, EAN como texto, data em dd/mm/aaaa. Larguras tiradas do conteudo
     * real: descricao e o campo longo, EAN tem 13 digitos, preco e data sao
     * curtos.
     */
    private const COLUNAS_PRECO = [
        ['Farmácia', 16, 'texto', 'nome_farmacia'],
        ['Descrição', 60, 'texto', 'descricao'],
        ['Laboratório', 24, 'texto', 'laboratorio'],
        ['EAN', 16, 'ean', 'EAN'],
        ['Preço', 12, 'preco', 'preco'],
        ['Data', 12, 'data', 'data'],
    ];

    private const COLUNAS_VARIACAO = [
        ['Farmácia', 16, 'texto', 'nome_farmacia'],
        ['Descrição', 60, 'texto', 'descricao'],
        ['Laboratório', 24, 'texto', 'laboratorio'],
        ['EAN', 16, 'ean', 'EAN'],
        ['Preço anterior', 14, 'preco', 'preco_anterior'],
        ['Data anterior', 14, 'data', 'data_anterior'],
        ['Preço novo', 14, 'preco', 'preco'],
        ['Data da mudança', 16, 'data', 'data'],
        ['Variação (%)', 14, 'percentual', 'variacao'],
    ];

    public function export(Request $request) {
        $validator = Validator::make($request->all(), [
            'ean' => 'nullable|string|max:15',
            'descricao' => 'nullable|string|max:255',
            'farmacia' => 'nullable|string',
            'formato' => 'required|in:csv,excel',
            'priceType' => 'required|in:current,historical,variation',
            'data-inicio' => 'nullable|date',
            'data-fim' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Aumentos e reducoes tem filtros proprios e outra linha; dos outros
        // dois relatorios so compartilha o formato de saida.
        if ($request->query('priceType') === 'variation') {
            return $this->exportarVariacoes($request);
        }

        $ean = $request->query('ean');
        $descricao = $request->query('descricao');
        $farmacia = $request->query('farmacia');
        $formato = $request->query('formato');
        $priceType = $request->query('priceType');
        $dataInicio = $request->query('data-inicio');
        $dataFim = $request->query('data-fim');

        try {
            if ($priceType === 'current') {
                $query = DB::table('precos_atuais as p')
                    ->join('produtos as prod', 'p.produto_id', '=', 'prod.produto_id')
                    ->join('farmacias as f', 'p.farmacia_id', '=', 'f.farmacia_id')
                    ->leftJoin('informacoes_produtos as ip', function ($join) {
                        $join->on('prod.produto_id', '=', 'ip.produto_id')
                            ->on('p.farmacia_id', '=', 'ip.farmacia_id');
                    })
                    ->select([
                        'f.nome_farmacia',
                        'prod.descricao',
                        'prod.laboratorio',
                        'prod.EAN',
                        'p.preco',
                        'p.data'
                    ]);

                // Mesma regra da busca: produto que nenhuma coleta encontra ha
                // mais de 30 dias sai do relatorio de preco atual, porque o
                // preco guardado dele nao vale mais nada. Ate agora as duas
                // telas discordavam sobre quais produtos existem — a busca
                // escondia o inativo e o relatorio o entregava.
                //
                // Linhas sem registro em informacoes_produtos ficam: nao ha o
                // que avaliar. `incluir_inativos` desfaz o filtro, para quem
                // precisa do retrato completo.
                if (!$request->query->has('incluir_inativos')) {
                    $query->where(function ($q) {
                        $q->where('ip.ativo', 1)->orWhereNull('ip.informacao_id');
                    });
                }
            } else {
                $query = DB::table('precos as p')
                    ->join('produtos as prod', 'p.produto_id', '=', 'prod.produto_id')
                    ->join('farmacias as f', 'p.farmacia_id', '=', 'f.farmacia_id')
                    ->select([
                        'f.nome_farmacia',
                        'prod.descricao',
                        'prod.laboratorio',
                        'prod.EAN',
                        'p.preco',
                        'p.data'
                    ]);

                if ($dataInicio && $dataFim) {
                    $query->whereBetween('p.data', [$dataInicio, $dataFim]);
                } elseif ($dataInicio) {
                    $query->where('p.data', '>=', $dataInicio);
                } elseif ($dataFim) {
                    $query->where('p.data', '<=', $dataFim);
                }
            }

            // Apply filters
            if ($ean) {
                $query->where('prod.EAN', $ean);
            } elseif ($descricao) {
                $query->where('prod.descricao', 'like', '%' . $descricao . '%');
            } elseif ($farmacia) {
                $farmaciaIds = array_map('intval', preg_split('/[\s+,;]+/', $farmacia));
                $query->whereIn('f.farmacia_id', $farmaciaIds);
            }

            $query->orderBy('p.preco', 'asc');

            if ($formato === 'csv') {
                return $this->streamCSV($query, self::COLUNAS_PRECO, 'relatorio');
            } else {
                return $this->streamExcel($query, self::COLUNAS_PRECO, 'relatorio');
            }
        } catch (\Exception $e) {
            Log::error('Erro ao exportar dados', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao exportar dados'], 500);
        }
    }

    /**
     * Uma consulta so, percorrida em cursor, em vez de paginada.
     *
     * `chunk(2000)` parece a escolha economica, mas ele pagina por LIMIT/OFFSET:
     * cada pagina refaz o join e a ordenacao inteiros e joga fora as primeiras N
     * linhas. Com 179 mil linhas sao 90 consultas, e o custo de cada uma cresce
     * com a profundidade — medido em producao: 0,03s no OFFSET 0 e 1,19s no
     * OFFSET 170.000. A soma dava 71s para um relatorio que, pedido de uma vez,
     * a mesma base entrega em 2,11s.
     *
     * `cursor()` faz exatamente essa consulta unica e vai entregando linha a
     * linha. E a diferenca entre O(n²) e O(n), nao uma economia de constante.
     */
    private function streamCSV($query, array $colunas, string $prefixo) {
        $filename = $prefixo . '_' . date('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            // `X-Accel-Buffering: no` saiu porque a resposta nao demora mais o
            // suficiente para justificar desligar o buffer do nginx.
            //
            // Ele NAO afetava a compressao, ao contrario do que parecia: o
            // nginx comprime resposta em stream do mesmo jeito. Medido — o
            // corpo ja saia com 33,9 bytes por linha, e sem gzip a mesma linha
            // ocupa 103,9. Os 18,6 MB de texto continuam chegando como 5,8 MB
            // no fio, antes e depois desta mudanca.
        ];

        return response()->stream(function () use ($query, $colunas) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, array_column($colunas, 0));

            foreach ($query->cursor() as $item) {
                fputcsv($handle, array_map(
                    fn ($coluna) => $this->valorCsv($coluna[2], $item->{$coluna[3]} ?? null),
                    $colunas
                ));
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * XLSX escrito em streaming, com o OpenSpout.
     *
     * O PhpSpreadsheet monta a planilha inteira como objetos em memoria e so
     * entao grava: um milhao de celulas viram um milhao de objetos, e o
     * relatorio completo levava 128s. Cortar o `setAutoSize` — que media o
     * texto de cada celula para decidir seis larguras — trouxe para 79s, mas o
     * resto e do modelo em memoria, nao de um detalhe.
     *
     * O OpenSpout escreve linha a linha direto no zip de saida e esquece a
     * linha anterior. Memoria constante, e nada da planilha precisa existir ao
     * mesmo tempo.
     */
    private function streamExcel($query, array $colunas, string $prefixo) {
        $filename = $prefixo . '_' . date('Y-m-d_His') . '.xlsx';

        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ];

        return response()->stream(function () use ($query, $colunas) {
            $opcoes = new XlsxOptions();

            // Colunas indexadas a partir de 1 (A = 1).
            foreach ($colunas as $indice => $coluna) {
                $opcoes->setColumnWidth((float) $coluna[1], $indice + 1);
            }

            $writer = new XlsxWriter($opcoes);
            // Escreve num stream, e nao via ZipArchive: da para gravar direto
            // na saida da resposta, sem arquivo temporario no meio.
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValuesWithStyle(
                array_column($colunas, 0),
                (new Style())->withFontBold(true)
            ));

            $estilos = [
                'preco'      => (new Style())->withFormat('#,##0.00'),
                // Sinal explicito: numa coluna que mistura altas e quedas, o
                // "+" e o que separa as duas numa olhada.
                'percentual' => (new Style())->withFormat('+0.00;-0.00;0.00'),
            ];

            foreach ($query->cursor() as $item) {
                $writer->addRow(new Row(array_map(
                    fn ($coluna) => $this->celulaExcel($coluna[2], $item->{$coluna[3]} ?? null, $estilos),
                    $colunas
                )));
            }

            $writer->close();
        }, 200, $headers);
    }

    private function exportarVariacoes(Request $request)
    {
        $validator = VariacaoPreco::validador($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $query = VariacaoPreco::consulta(VariacaoPreco::filtros($request));

        return $request->query('formato') === 'csv'
            ? $this->streamCSV($query, self::COLUNAS_VARIACAO, 'relatorio_variacoes')
            : $this->streamExcel($query, self::COLUNAS_VARIACAO, 'relatorio_variacoes');
    }

    private function valorCsv(string $tipo, $valor): string
    {
        return match ($tipo) {
            // Tabulacao na frente para o Excel nao mostrar 7,896E+12 no lugar
            // do codigo ao abrir o CSV.
            'ean'                 => "\t" . $valor,
            'preco', 'percentual' => $valor === null ? '' : number_format((float) $valor, 2, '.', ''),
            'data'                => $valor ? date('d/m/Y', strtotime($valor)) : '',
            default               => preg_replace('/[\r\n]+/', ' ', trim((string) $valor)),
        };
    }

    /** @param  array<string, Style>  $estilos */
    private function celulaExcel(string $tipo, $valor, array $estilos): Cell
    {
        if ($valor === null) {
            return Cell::fromValue('');
        }

        return match ($tipo) {
            'preco', 'percentual' => Cell::fromValue((float) $valor, $estilos[$tipo]),
            'data'                => Cell::fromValue(date('d/m/Y', strtotime($valor))),
            // O EAN chega do banco como string, e o OpenSpout so cria celula
            // numerica a partir de int/float de verdade. Entao ele fica texto
            // sozinho, sem precisar do prefixo de tabulacao do CSV.
            'ean'                 => Cell::fromValue((string) $valor),
            default               => Cell::fromValue($valor),
        };
    }
}
