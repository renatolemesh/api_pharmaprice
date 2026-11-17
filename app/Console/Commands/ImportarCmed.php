<?php

namespace App\Console\Commands;

use App\Support\GrupoCmed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Importa a lista de precos da CMED/Anvisa para `cmed_medicamentos` e
 * `cmed_classes`: substancia e classe terapeutica de cada EAN.
 *
 * A Anvisa republica a planilha todo mes, com nome de arquivo novo. Sem
 * argumento, o comando descobre o link vigente na pagina da CMED e baixa.
 *
 *   php artisan cmed:importar                 baixa a lista vigente e importa
 *   php artisan cmed:importar lista.xlsx      importa um arquivo ja baixado
 *   php artisan cmed:importar --so-termos     so reaplica cmed_termos.php
 *
 * As tabelas sao substituidas por inteiro, numa transacao: quem consulta
 * durante a importacao ve a lista antiga ate o commit, nunca metade.
 */
class ImportarCmed extends Command
{
    private const PAGINA = 'https://www.gov.br/anvisa/pt-br/assuntos/medicamentos/cmed/precos';

    /** O gov.br recusa cliente sem User-Agent de navegador. */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; importador-cmed)';

    /** Colunas lidas, pelo cabecalho sem acento. As demais sao ignoradas. */
    private const COLUNAS = [
        'substancia'         => 'substancia',
        'laboratorio'        => 'laboratorio',
        'produto'            => 'produto',
        'apresentacao'       => 'apresentacao',
        'classe terapeutica' => 'classe',
        'ean 1'              => 'ean1',
        'ean 2'              => 'ean2',
        'ean 3'              => 'ean3',
    ];

    protected $signature = 'cmed:importar
        {arquivo? : Caminho de um .xlsx da CMED. Sem ele, baixa a lista vigente}
        {--so-termos : Apenas reaplica database/data/cmed_termos.php às classes}
        {--minimo=10000 : Recusa a importação se a planilha tiver menos EANs que isto}';

    protected $description = 'Importa substância e classe terapêutica por EAN da lista de preços da CMED/Anvisa';

    public function handle(): int
    {
        if ($this->option('so-termos')) {
            return $this->reaplicarTermos();
        }

        $arquivo = $this->argument('arquivo') ?? $this->baixar();
        if (!$arquivo || !is_file($arquivo)) {
            $this->error('Arquivo não encontrado: ' . ($arquivo ?: '(download falhou)'));
            return self::FAILURE;
        }

        [$medicamentos, $classes] = $this->ler($arquivo);

        // Download truncado ou planilha em formato novo produzem poucas linhas
        // ou nenhuma. Sem esta guarda, a transacao abaixo apagaria a lista boa
        // e gravaria o resto - e toda busca por grupo voltaria vazia.
        $minimo = (int) $this->option('minimo');
        if (count($medicamentos) < $minimo) {
            $this->error(sprintf(
                'A planilha trouxe %d EANs, menos que o mínimo de %d. Nada foi alterado.',
                count($medicamentos),
                $minimo
            ));
            return self::FAILURE;
        }

        DB::transaction(function () use ($medicamentos, $classes) {
            DB::table('cmed_medicamentos')->delete();
            DB::table('cmed_classes')->delete();

            foreach (array_chunk(array_values($classes), 500) as $lote) {
                DB::table('cmed_classes')->insert($lote);
            }
            foreach (array_chunk(array_values($medicamentos), 1000) as $lote) {
                DB::table('cmed_medicamentos')->insert($lote);
            }
        });

        $naBase = DB::table('produtos as p')
            ->join('cmed_medicamentos as c', 'c.ean', '=', 'p.EAN')
            ->count();

        $this->info(sprintf(
            '%d EANs em %d classes importados. %d produtos da base agora têm grupo.',
            count($medicamentos),
            count($classes),
            $naBase
        ));

        return self::SUCCESS;
    }

    private function reaplicarTermos(): int
    {
        $classes = DB::table('cmed_classes')->pluck('codigo');
        foreach ($classes as $codigo) {
            DB::table('cmed_classes')
                ->where('codigo', $codigo)
                ->update(['termos' => GrupoCmed::termosDe($codigo)]);
        }

        $this->info(count($classes) . ' classes atualizadas com os termos do arquivo.');

        return self::SUCCESS;
    }

    /** Descobre o link da planilha PMC vigente e baixa. Devolve o caminho local. */
    private function baixar(): ?string
    {
        $this->line('Procurando a lista vigente em ' . self::PAGINA);

        $pagina = Http::withUserAgent(self::USER_AGENT)->timeout(60)->get(self::PAGINA);
        if (!$pagina->ok() || !preg_match('/href="([^"]*lista_pmc_[^"]*\.xlsx[^"]*)"/i', $pagina->body(), $m)) {
            $this->error('Não achei o link da planilha PMC na página da CMED.');
            return null;
        }

        $url = html_entity_decode($m[1]);
        if (str_starts_with($url, '/')) {
            $url = 'https://www.gov.br' . $url;
        }

        $destino = storage_path('app/cmed/lista_pmc.xlsx');
        if (!is_dir(dirname($destino))) {
            mkdir(dirname($destino), 0775, true);
        }

        $this->line('Baixando ' . $url);
        $resposta = Http::withUserAgent(self::USER_AGENT)->timeout(300)->sink($destino)->get($url);

        return $resposta->ok() ? $destino : null;
    }

    /**
     * Le a planilha e devolve [medicamentos por EAN, classes por codigo].
     *
     * O cabecalho e procurado, nao presumido: o arquivo tem uma aba de capa
     * antes dos dados, e em edicoes antigas a tabela comecava dezenas de
     * linhas abaixo de um texto de apresentacao.
     */
    private function ler(string $arquivo): array
    {
        $medicamentos = [];
        $classes = [];

        $reader = new Reader();
        $reader->open($arquivo);

        foreach ($reader->getSheetIterator() as $aba) {
            $indices = null;

            foreach ($aba->getRowIterator() as $linha) {
                $celulas = $linha->toArray();

                if ($indices === null) {
                    $indices = $this->indicesDoCabecalho($celulas);
                    continue;
                }

                $valor = fn (string $campo) => isset($indices[$campo])
                    ? trim((string) ($celulas[$indices[$campo]] ?? ''))
                    : '';

                $classeCodigo = null;
                // "R5A - ANTIGRIPAIS SEM ANTIINFECCIOSOS"
                if (preg_match('/^\s*([A-Za-z0-9]{1,10})\s+-\s+(.+)$/u', $valor('classe'), $m)) {
                    $classeCodigo = strtoupper($m[1]);
                    $classes[$classeCodigo] ??= [
                        'codigo' => $classeCodigo,
                        'nome'   => mb_substr(trim($m[2]), 0, 200),
                        'termos' => GrupoCmed::termosDe($classeCodigo),
                    ];
                }

                foreach (['ean1', 'ean2', 'ean3'] as $campo) {
                    $ean = $this->ean($indices[$campo] ?? null, $celulas);
                    if ($ean === null) {
                        continue;
                    }
                    $medicamentos[$ean] = [
                        'ean'           => $ean,
                        'classe_codigo' => $classeCodigo,
                        'substancia'    => $valor('substancia') ?: null,
                        'laboratorio'   => mb_substr($valor('laboratorio'), 0, 255) ?: null,
                        'produto'       => mb_substr($valor('produto'), 0, 255) ?: null,
                        'apresentacao'  => $valor('apresentacao') ?: null,
                    ];
                }
            }
        }

        $reader->close();

        return [$medicamentos, $classes];
    }

    /** Posicao de cada coluna, se esta linha for o cabecalho; senao null. */
    private function indicesDoCabecalho(array $celulas): ?array
    {
        $indices = [];
        foreach ($celulas as $i => $celula) {
            $nome = is_string($celula) ? GrupoCmed::normalizar($celula) : '';
            if (isset(self::COLUNAS[$nome])) {
                $indices[self::COLUNAS[$nome]] = $i;
            }
        }

        return isset($indices['ean1'], $indices['classe']) ? $indices : null;
    }

    /**
     * EAN de uma celula, so com digitos; null para vazio e para o "-" que a
     * planilha usa em EAN 2 e EAN 3 quando nao existem.
     */
    private function ean(?int $indice, array $celulas): ?string
    {
        $bruto = $indice === null ? null : ($celulas[$indice] ?? null);

        // Celula numerica chega como float; o cast direto para string daria
        // "7.89123456789E+12".
        if (is_float($bruto)) {
            $bruto = number_format($bruto, 0, '', '');
        }

        $digitos = preg_replace('/\D/', '', (string) $bruto);

        return strlen($digitos) >= 8 && strlen($digitos) <= 15 ? $digitos : null;
    }
}
