<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;

/**
 * Copia os dados do MySQL antigo (conexao `mysql_legado`) para o Postgres.
 *
 * Serve uma vez, na migracao, e de novo se for preciso refazer. O schema do
 * Postgres vem das migrations (`php artisan migrate` antes); daqui so saem
 * linhas. Copiar o schema junto, com uma ferramenta tipo pgloader, traria os
 * tipos do MySQL (tinyint no lugar de boolean, sem a coluna de busca) e um
 * banco diferente do que os testes e um `migrate` produzem.
 *
 *   php artisan banco:copiar-do-mysql             copia, recusando tabela com dados
 *   php artisan banco:copiar-do-mysql --substituir   esvazia o destino antes
 *   php artisan banco:copiar-do-mysql --conferir     so compara origem e destino
 *
 * A escrita no MySQL tem que estar parada durante a copia (API em
 * manutencao): o que entrar depois de uma tabela ser lida fica para tras.
 */
class CopiarDoMysql extends Command
{
    protected $signature = 'banco:copiar-do-mysql
                            {--substituir : Esvazia as tabelas de destino antes de copiar}
                            {--conferir : Nao copia; so compara contagens e somas}
                            {--lote=10000 : Linhas por COPY}';

    protected $description = 'Copia os dados do MySQL antigo para o Postgres';

    /**
     * Tabelas na ordem das chaves estrangeiras, com a coluna que ordena a
     * leitura e, quando tem, a sequencia a acertar no fim.
     *
     * Ficam de fora: `migrations` (o Postgres tem a sua), `cache`, `sessions`,
     * `jobs` e afins (estado de passagem) e a `latest_precos_view`, que deixou
     * de existir.
     */
    private const TABELAS = [
        'farmacias'              => ['ordem' => 'farmacia_id', 'serial' => 'farmacia_id'],
        'produtos'               => ['ordem' => 'produto_id', 'serial' => 'produto_id'],
        'informacoes_produtos'   => ['ordem' => 'informacao_id', 'serial' => 'informacao_id'],
        'links'                  => ['ordem' => null, 'serial' => null],
        'precos'                 => ['ordem' => 'preco_id', 'serial' => 'preco_id'],
        'precos_atuais'          => ['ordem' => null, 'serial' => null],
        'execucoes_coleta'       => ['ordem' => 'execucao_id', 'serial' => 'execucao_id'],
        'cmed_classes'           => ['ordem' => 'codigo', 'serial' => null],
        'cmed_medicamentos'      => ['ordem' => 'ean', 'serial' => null],
        'users'                  => ['ordem' => 'id', 'serial' => 'id'],
        'personal_access_tokens' => ['ordem' => 'id', 'serial' => 'id'],
    ];

    /** Somas que tem que bater alem da contagem: dinheiro e ponteiros. */
    private const SOMAS = [
        'precos'        => ['preco', 'preco_anterior', 'preco_id'],
        'precos_atuais' => ['preco', 'preco_id'],
        'produtos'      => ['produto_id'],
    ];

    public function handle(): int
    {
        $origem = DB::connection('mysql_legado');
        $destino = DB::connection();

        if ($destino->getDriverName() !== 'pgsql') {
            $this->error('A conexao padrao precisa ser o Postgres (DB_CONNECTION=pgsql).');
            return self::FAILURE;
        }

        $tabelas = array_filter(
            self::TABELAS,
            fn ($t) => $this->existeNaOrigem($origem, $t),
            ARRAY_FILTER_USE_KEY
        );

        if ($this->option('conferir')) {
            return $this->conferir($origem, $destino, $tabelas) ? self::SUCCESS : self::FAILURE;
        }

        $ocupadas = array_filter(array_keys($tabelas), fn ($t) => $destino->table($t)->exists());
        if ($ocupadas && !$this->option('substituir')) {
            $this->error('Ja tem dados no Postgres: ' . implode(', ', $ocupadas) . '. Use --substituir para apagar e copiar de novo.');
            return self::FAILURE;
        }

        if ($this->option('substituir')) {
            // Uma instrucao so, para o TRUNCATE nao tropecar nas chaves
            // estrangeiras entre elas.
            $destino->statement('TRUNCATE ' . implode(', ', array_keys($tabelas)) . ' RESTART IDENTITY CASCADE');
        }

        $inicio = microtime(true);

        foreach ($tabelas as $tabela => $config) {
            $t0 = microtime(true);
            $linhas = $this->copiar($origem, $destino, $tabela, $config['ordem']);
            $this->line(sprintf('  %-24s %10s linhas  %6.1fs', $tabela, number_format($linhas, 0, ',', '.'), microtime(true) - $t0));

            if ($config['serial']) {
                // Sem isto o proximo INSERT tentaria o id 1 e bateria na chave.
                $destino->statement(
                    "SELECT setval(pg_get_serial_sequence(?, ?), COALESCE(MAX({$config['serial']}), 1), MAX({$config['serial']}) IS NOT NULL) FROM {$tabela}",
                    [$tabela, $config['serial']]
                );
            }
        }

        // Estatisticas para o planejador: sem elas a primeira consulta de cada
        // tabela recem-carregada escolhe plano as cegas.
        $destino->statement('ANALYZE');

        $this->info(sprintf('Copia terminada em %.0fs.', microtime(true) - $inicio));

        return $this->conferir($origem, $destino, $tabelas) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Le em streaming do MySQL e escreve com COPY no Postgres.
     *
     * Leitura sem buffer, ou o PDO do MySQL traria as 2,8 milhoes de linhas de
     * `precos` para a memoria antes de devolver a primeira. COPY em vez de
     * INSERT porque e o caminho de carga do Postgres: uma ida ao banco por
     * lote, sem montar SQL.
     */
    private function copiar(Connection $origem, Connection $destino, string $tabela, ?string $ordem): int
    {
        $colunas = $this->colunasEmComum($origem, $destino, $tabela);
        $lista = implode(', ', array_map(fn ($c) => "`{$c}`", $colunas));

        $leitura = $origem->getPdo();
        $leitura->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $sql = "SELECT {$lista} FROM `{$tabela}`" . ($ordem ? " ORDER BY `{$ordem}`" : '');
        $cursor = $leitura->query($sql, PDO::FETCH_NUM);

        $escrita = $destino->getPdo();
        $alvo = $tabela . ' (' . implode(', ', array_map(fn ($c) => "\"{$c}\"", $colunas)) . ')';
        $lote = [];
        $total = 0;
        $tamanho = max(1, (int) $this->option('lote'));

        $destino->beginTransaction();
        try {
            foreach ($cursor as $linha) {
                $lote[] = $this->linhaCopy($linha);
                if (count($lote) >= $tamanho) {
                    $total += $this->gravar($escrita, $alvo, $lote);
                    $lote = [];
                }
            }
            if ($lote) {
                $total += $this->gravar($escrita, $alvo, $lote);
            }
            $destino->commit();
        } catch (\Throwable $e) {
            $destino->rollBack();
            throw $e;
        } finally {
            $cursor->closeCursor();
            $leitura->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        return $total;
    }

    private function gravar($pdo, string $alvo, array $lote): int
    {
        $ok = $pdo instanceof \Pdo\Pgsql
            ? $pdo->copyFromArray($alvo, $lote)
            : $pdo->pgsqlCopyFromArray($alvo, $lote);

        if (!$ok) {
            throw new RuntimeException("COPY em {$alvo} falhou: " . implode(' ', $pdo->errorInfo()));
        }

        return count($lote);
    }

    /**
     * Uma linha no formato texto do COPY: campos separados por tab, \N para
     * nulo, e barra, tab e quebra de linha escapados.
     */
    private function linhaCopy(array $valores): string
    {
        return implode("\t", array_map(function ($v) {
            if ($v === null) {
                return '\N';
            }

            return strtr((string) $v, ["\\" => "\\\\", "\t" => "\\t", "\n" => "\\n", "\r" => "\\r"]);
        }, $valores));
    }

    /**
     * Colunas que existem dos dois lados, na ordem do destino. Coluna gerada
     * (`produtos.texto_busca`) fica de fora: o Postgres calcula, e recusa
     * quem tenta escrever nela.
     */
    private function colunasEmComum(Connection $origem, Connection $destino, string $tabela): array
    {
        $daOrigem = array_map('strtolower', Schema::connection('mysql_legado')->getColumnListing($tabela));

        $doDestino = array_column($destino->select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? AND is_generated = 'NEVER'
             ORDER BY ordinal_position",
            [$tabela]
        ), 'column_name');

        $faltando = array_diff(array_map('strtolower', $doDestino), $daOrigem);
        if ($faltando) {
            // Coluna nova no Postgres sem par no MySQL: precisa ter default, e
            // e melhor saber disso aqui do que pelo erro do COPY.
            $this->warn("  {$tabela}: sem origem para " . implode(', ', $faltando) . ' (fica com o default)');
        }

        return array_values(array_filter($doDestino, fn ($c) => in_array(strtolower($c), $daOrigem, true)));
    }

    private function existeNaOrigem(Connection $origem, string $tabela): bool
    {
        return Schema::connection('mysql_legado')->hasTable($tabela);
    }

    /** Contagem por tabela e somas das colunas que importam, lado a lado. */
    private function conferir(Connection $origem, Connection $destino, array $tabelas): bool
    {
        $linhas = [];
        $tudoCerto = true;

        foreach (array_keys($tabelas) as $tabela) {
            $exprs = ['COUNT(*) AS n'];
            foreach (self::SOMAS[$tabela] ?? [] as $coluna) {
                $exprs[] = "COALESCE(SUM({$coluna}), 0) AS s_{$coluna}";
            }
            $select = implode(', ', $exprs);

            $a = (array) $origem->selectOne("SELECT {$select} FROM {$tabela}");
            $b = (array) $destino->selectOne("SELECT {$select} FROM {$tabela}");

            foreach ($a as $campo => $valor) {
                // Comparacao como texto de numero normalizado: o MySQL devolve
                // "123.40" onde o Postgres devolve "123.4".
                $igual = bccomp((string) $valor, (string) $b[$campo], 2) === 0;
                $tudoCerto = $tudoCerto && $igual;
                $linhas[] = [$tabela, $campo === 'n' ? 'linhas' : substr($campo, 2), $valor, $b[$campo], $igual ? 'ok' : 'DIFERENTE'];
            }
        }

        $this->table(['tabela', 'medida', 'mysql', 'postgres', ''], $linhas);

        $tudoCerto
            ? $this->info('Origem e destino conferem.')
            : $this->error('Origem e destino NAO conferem.');

        return $tudoCerto;
    }
}
