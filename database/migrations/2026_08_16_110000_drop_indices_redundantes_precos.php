<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove os indices redundantes de `precos`.
     *
     * A tabela acumulou 10 indices secundarios ao longo do tempo, criados em
     * momentos diferentes e boa parte deles a mao, fora das migrations. O
     * resultado: 621 MB de indice para 192 MB de dados - mais de 3x. Todo
     * INSERT paga a manutencao dos 10, e a coleta diaria escreve ~17 mil
     * precos por farmacia.
     *
     * O criterio para remover foi PREFIXO, nao palpite: um indice (a, b) nao
     * serve para nada se existe (a, b, c), porque o InnoDB usa o mais longo
     * exatamente do mesmo jeito para qualquer consulta que o curto atenderia.
     * Nenhuma consulta perde caminho de acesso aqui.
     *
     *   idx_precos_preco_id            (preco_id)                      = PRIMARY
     *   idx_latest_preco               (produto_id, farmacia_id, preco_id)
     *                                  -> prefixo de idx_precos_covering
     *   idx_precos_produto_farmacia_id (produto_id, farmacia_id, preco_id DESC)
     *                                  -> prefixo de idx_precos_covering
     *   idx_precos_produto_farmacia    (produto_id, farmacia_id)
     *                                  -> prefixo de idx_precos_produto_farmacia_data
     *   idx_precos_farmacia_produto    (farmacia_id, produto_id)
     *                                  -> prefixo de idx_precos_farmacia_produto_data
     *   idx_precos_data                (data)
     *                                  -> prefixo de idx_precos_data_farmacia
     *
     * Os dois primeiros tinham, alem disso, ZERO leituras em 19h de
     * `sys.schema_index_statistics` - incluindo uma coleta diaria inteira.
     * Os outros quatro eram lidos, mas so porque existiam: o indice mais longo
     * atende as mesmas consultas.
     *
     * Sobram quatro secundarios, cada um com um caminho de acesso proprio:
     *
     *   idx_precos_produto_farmacia_data  (produto_id, farmacia_id, data)
     *   idx_precos_farmacia_produto_data  (farmacia_id, produto_id, data)
     *   idx_precos_data_farmacia          (data, farmacia_id)
     *   idx_precos_covering               (produto_id, farmacia_id, preco_id DESC, preco, data)
     *
     * Nota: `idx_precos_data` e `idx_latest_preco` sao criados por migrations
     * anteriores (2025_10_15_004639_indexes e 2024_06_13_174456_create_precos_table).
     * Esta migration roda DEPOIS das duas, entao o estado final e o certo tanto
     * num banco existente quanto num `migrate:fresh`.
     */
    private const REDUNDANTES = [
        'idx_precos_preco_id',
        'idx_latest_preco',
        'idx_precos_produto_farmacia_id',
        'idx_precos_produto_farmacia',
        'idx_precos_farmacia_produto',
        'idx_precos_data',
    ];

    /** Como recriar cada um, para o `down()` devolver a tabela ao estado anterior. */
    private const RECRIAR = [
        'idx_precos_preco_id'            => '(preco_id)',
        'idx_latest_preco'               => '(produto_id, farmacia_id, preco_id)',
        'idx_precos_produto_farmacia_id' => '(produto_id, farmacia_id, preco_id DESC)',
        'idx_precos_produto_farmacia'    => '(produto_id, farmacia_id)',
        'idx_precos_farmacia_produto'    => '(farmacia_id, produto_id)',
        'idx_precos_data'                => '(data)',
    ];

    public function up(): void
    {
        // A coleta escreve em `precos` o dia inteiro. Um ALTER espera o lock de
        // metadados das transacoes abertas, e o padrao do MySQL para essa espera
        // e um ano - ou seja, na pratica, para sempre. Se nao der para pegar o
        // lock em 30s, e melhor a migration falhar do que travar a coleta.
        DB::statement('SET SESSION lock_wait_timeout = 30');

        foreach (self::REDUNDANTES as $nome) {
            if (!$this->indiceExiste('precos', $nome)) {
                continue;
            }

            // SQL cru em vez de `Schema::table`: o Blueprint do Laravel emite um
            // ALTER por chamada e nao aceita ALGORITHM/LOCK. Numa tabela de 2
            // milhoes de linhas que a coleta escreve o dia inteiro, deixar o
            // ALTER pegar lock de escrita pararia a coleta no meio.
            DB::statement("ALTER TABLE precos DROP INDEX `{$nome}`, ALGORITHM=INPLACE, LOCK=NONE");
        }
    }

    public function down(): void
    {
        foreach (self::RECRIAR as $nome => $colunas) {
            if ($this->indiceExiste('precos', $nome)) {
                continue;
            }

            DB::statement("ALTER TABLE precos ADD INDEX `{$nome}` {$colunas}, ALGORITHM=INPLACE, LOCK=NONE");
        }
    }

    private function indiceExiste(string $tabela, string $indice): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $tabela)
            ->where('index_name', $indice)
            ->exists();
    }
};
