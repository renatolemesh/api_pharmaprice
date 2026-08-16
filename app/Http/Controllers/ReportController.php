<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    public function export(Request $request) {
        $validator = Validator::make($request->all(), [
            'ean' => 'nullable|string|max:15',
            'descricao' => 'nullable|string|max:255',
            'farmacia' => 'nullable|string',
            'formato' => 'required|in:csv,excel',
            'priceType' => 'required|in:current,historical',
            'data-inicio' => 'nullable|date',
            'data-fim' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
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
                return $this->streamCSV($query);
            } else {
                return $this->streamExcel($query);
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
    private function streamCSV($query) {
        $filename = 'relatorio_' . date('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            // Sem `X-Accel-Buffering: no`: ele desliga o buffer do nginx e, com
            // ele, o gzip. Sao 6 MB de texto que comprimem para cerca de 1 MB, e
            // a resposta inteira agora fica pronta em segundos — nao ha mais
            // stream longo para proteger.
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, ['Farmácia', 'Descrição', 'Laboratório', 'EAN', 'Preço', 'Data']);

            $clean = fn ($v) => preg_replace('/[\r\n]+/', ' ', trim((string) $v));

            foreach ($query->cursor() as $item) {
                fputcsv($handle, [
                    $clean($item->nome_farmacia),
                    $clean($item->descricao),
                    $clean($item->laboratorio ?? ''),
                    "\t" . $item->EAN,
                    number_format((float) $item->preco, 2, '.', ''),
                    date('d/m/Y', strtotime($item->data))
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    private function streamExcel($query) {
        $filename = 'relatorio_' . date('Y-m-d_His') . '.xlsx';

        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Relatório');

        // Header row
        $sheet->fromArray(['Farmácia', 'Descrição', 'Laboratório', 'EAN', 'Preço', 'Data'], null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        // Mesma troca de `chunk()` por `cursor()` do CSV, e pelo mesmo motivo:
        // a paginacao por OFFSET refazia a consulta inteira a cada pagina.
        $row = 2;
        foreach ($query->cursor() as $item) {
            $sheet->setCellValue("A$row", $item->nome_farmacia);
            $sheet->setCellValue("B$row", $item->descricao);
            $sheet->setCellValue("C$row", $item->laboratorio ?? '');
            $sheet->setCellValueExplicit("D$row", $item->EAN, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("E$row", (float) $item->preco);
            $sheet->setCellValue("F$row", date('d/m/Y', strtotime($item->data)));
            $row++;
        }

        // Format price column as number
        $sheet->getStyle('E2:E' . ($row - 1))
            ->getNumberFormat()
            ->setFormatCode('#,##0.00');

        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->stream(function () use ($writer, $spreadsheet) {
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 200, $headers);
    }
}
