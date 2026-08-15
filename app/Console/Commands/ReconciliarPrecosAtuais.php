<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconstroi `precos_atuais` a partir de `precos`.
 *
 * A projecao e escrita na mesma transacao do insert, entao em uso normal ela
 * nao diverge. Este comando existe para o que acontece fora do caminho normal:
 * carga manual por SQL, restauracao de backup parcial, ou uma versao antiga da
 * aplicacao rodando durante um deploy. Sem ele, a unica saida seria refazer a
 * migration.
 *
 *   php artisan precos:reconciliar --check   diz se divergiu, sem escrever
 *   php artisan precos:reconciliar           corrige
 */
class ReconciliarPrecosAtuais extends Command
{
    protected $signature = 'precos:reconciliar {--check : Apenas relata divergências, não escreve}';

    protected $description = 'Reconstrói precos_atuais a partir do log em precos';

    public function handle(): int
    {
        $divergentes = DB::selectOne('
            SELECT COUNT(*) AS total
            FROM (
                SELECT p.farmacia_id, p.produto_id, p.preco, p.data, p.preco_id
                FROM precos p
                INNER JOIN (
                    SELECT farmacia_id, produto_id, MAX(preco_id) AS max_id
                    FROM precos
                    WHERE farmacia_id IS NOT NULL AND produto_id IS NOT NULL
                    GROUP BY farmacia_id, produto_id
                ) ult ON p.preco_id = ult.max_id
                WHERE p.preco IS NOT NULL
            ) esperado
            LEFT JOIN precos_atuais a
              ON a.farmacia_id = esperado.farmacia_id
             AND a.produto_id  = esperado.produto_id
            WHERE a.produto_id IS NULL
               OR a.preco_id  <> esperado.preco_id
               -- Valor tambem, e nao so o ponteiro: uma escrita direta por SQL
               -- pode estragar o preco mantendo o preco_id coerente, e a
               -- conferencia passaria batida.
               OR NOT (a.preco <=> esperado.preco)
               OR NOT (a.data  <=> esperado.data)
        ')->total;

        $orfas = DB::selectOne('
            SELECT COUNT(*) AS total
            FROM precos_atuais a
            LEFT JOIN precos p
              ON p.farmacia_id = a.farmacia_id AND p.produto_id = a.produto_id
            WHERE p.preco_id IS NULL
        ')->total;

        if ($divergentes === 0 && $orfas === 0) {
            $this->info('precos_atuais está de acordo com precos.');
            return self::SUCCESS;
        }

        $this->warn("Divergências: {$divergentes} desatualizada(s), {$orfas} órfã(s).");

        if ($this->option('check')) {
            return self::FAILURE;
        }

        DB::transaction(function () {
            // Linha sem contrapartida em `precos` nao tem como ser corrigida
            // pelo upsert abaixo — some.
            DB::statement('
                DELETE a FROM precos_atuais a
                LEFT JOIN precos p
                  ON p.farmacia_id = a.farmacia_id AND p.produto_id = a.produto_id
                WHERE p.preco_id IS NULL
            ');

            DB::statement('
                INSERT INTO precos_atuais
                    (farmacia_id, produto_id, preco, data, preco_id, atualizado_em)
                SELECT p.farmacia_id, p.produto_id, p.preco, p.data, p.preco_id, NOW()
                FROM precos p
                INNER JOIN (
                    SELECT farmacia_id, produto_id, MAX(preco_id) AS max_id
                    FROM precos
                    WHERE farmacia_id IS NOT NULL AND produto_id IS NOT NULL
                    GROUP BY farmacia_id, produto_id
                ) ult ON p.preco_id = ult.max_id
                WHERE p.preco IS NOT NULL
                ON DUPLICATE KEY UPDATE
                    preco         = VALUES(preco),
                    data          = VALUES(data),
                    preco_id      = VALUES(preco_id),
                    atualizado_em = VALUES(atualizado_em)
            ');
        });

        $this->info('precos_atuais reconstruída.');
        return self::SUCCESS;
    }
}
