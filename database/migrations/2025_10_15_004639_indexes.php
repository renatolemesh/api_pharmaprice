<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indices de consulta da tabela `precos`.
     *
     * Idempotente de proposito: em producao estes indices foram criados a mao,
     * sem passar pelo sistema de migrations, entao a migration continua
     * marcada como pendente enquanto o efeito dela ja esta no banco. Criar de
     * novo aborta com "Duplicate key name" e derruba o `migrate` inteiro -
     * incluindo as migrations seguintes, que e o que se quer aplicar.
     */
    private const INDICES = [
        'idx_precos_farmacia_produto_data' => ['farmacia_id', 'produto_id', 'data'],
        'idx_precos_produto_farmacia_data' => ['produto_id', 'farmacia_id', 'data'],
        'idx_precos_data' => ['data'],
    ];

    public function up(): void
    {
        foreach (self::INDICES as $nome => $colunas) {
            if ($this->indiceExiste('precos', $nome)) {
                continue;
            }

            Schema::table('precos', function (Blueprint $table) use ($nome, $colunas) {
                $table->index($colunas, $nome);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDICES) as $nome) {
            if (!$this->indiceExiste('precos', $nome)) {
                continue;
            }

            Schema::table('precos', function (Blueprint $table) use ($nome) {
                $table->dropIndex($nome);
            });
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
