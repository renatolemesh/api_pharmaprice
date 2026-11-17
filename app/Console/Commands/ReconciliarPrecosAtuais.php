<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconstroi, a partir do log em `precos`, as duas coisas derivadas dele:
 * a tabela `precos_atuais` e a coluna `precos.preco_anterior`.
 *
 * As duas sao escritas na mesma transacao do insert, entao em uso normal nao
 * divergem. Este comando existe para o que acontece fora do caminho normal:
 * carga manual por SQL, restauracao de backup parcial, ou uma versao antiga da
 * aplicacao rodando durante um deploy. Sem ele, a unica saida seria refazer a
 * migration.
 *
 * `preco_anterior` entra aqui porque e o unico jeito de confirmar que a
 * projecao nao mentiu: o escritor grava o valor que ele *achava* ser o
 * anterior, e so o log sabe qual era de fato.
 *
 *   php artisan precos:reconciliar --check   diz se divergiu, sem escrever
 *   php artisan precos:reconciliar           corrige
 */
class ReconciliarPrecosAtuais extends Command
{
    protected $signature = 'precos:reconciliar {--check : Apenas relata divergências, não escreve}';

    protected $description = 'Reconstrói precos_atuais e precos.preco_anterior a partir do log em precos';

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
               OR a.preco IS DISTINCT FROM esperado.preco
               OR a.data  IS DISTINCT FROM esperado.data
        ')->total;

        $orfas = DB::selectOne('
            SELECT COUNT(*) AS total
            FROM precos_atuais a
            LEFT JOIN precos p
              ON p.farmacia_id = a.farmacia_id AND p.produto_id = a.produto_id
            WHERE p.preco_id IS NULL
        ')->total;

        // `preco_anterior` conferido contra a linha imediatamente anterior do
        // mesmo par, por preco_id — o mesmo criterio da carga inicial. `IS
        // DISTINCT FROM` porque a primeira mudanca de cada par tem anterior
        // nulo dos dois lados, e `<>` daria falso negativo nela.
        $anteriorErrado = DB::selectOne('
            SELECT COUNT(*) AS total
            FROM (
                SELECT preco_id, preco_anterior, data_anterior,
                       LAG(preco) OVER w AS esperado_preco,
                       LAG(data)  OVER w AS esperado_data
                FROM precos
                WINDOW w AS (PARTITION BY farmacia_id, produto_id ORDER BY preco_id)
            ) c
            WHERE c.preco_anterior IS DISTINCT FROM c.esperado_preco
               OR c.data_anterior  IS DISTINCT FROM c.esperado_data
        ')->total;

        if ($divergentes === 0 && $orfas === 0 && $anteriorErrado === 0) {
            $this->info('precos_atuais e precos.preco_anterior estão de acordo com precos.');
            return self::SUCCESS;
        }

        $this->warn(
            "Divergências: {$divergentes} desatualizada(s), {$orfas} órfã(s), "
            . "{$anteriorErrado} com preco_anterior errado."
        );

        if ($this->option('check')) {
            return self::FAILURE;
        }

        DB::transaction(function () {
            // Linha sem contrapartida em `precos` nao tem como ser corrigida
            // pelo upsert abaixo — some.
            DB::statement('
                DELETE FROM precos_atuais a
                WHERE NOT EXISTS (
                    SELECT 1 FROM precos p
                    WHERE p.farmacia_id = a.farmacia_id AND p.produto_id = a.produto_id
                )
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
                ON CONFLICT (farmacia_id, produto_id) DO UPDATE SET
                    preco         = EXCLUDED.preco,
                    data          = EXCLUDED.data,
                    preco_id      = EXCLUDED.preco_id,
                    atualizado_em = EXCLUDED.atualizado_em
            ');
        });

        $this->corrigirPrecoAnterior();

        $this->info('precos_atuais e precos.preco_anterior reconstruídos.');
        return self::SUCCESS;
    }

    /**
     * Recalcula `preco_anterior` a partir do log, em lotes por faixa de
     * produto_id.
     *
     * A faixa mantém pares inteiros do mesmo lado do corte, que é o que faz o
     * resultado ser idêntico ao de um UPDATE único. Cortar por preco_id
     * partiria a série de um par ao meio e o primeiro item de cada pedaço
     * ficaria sem anterior.
     *
     * So reescreve a linha que esta errada: no Postgres todo UPDATE cria uma
     * versao nova da linha, e regravar 2,8 milhoes de precos iguais so
     * incharia a tabela.
     */
    private function corrigirPrecoAnterior(): void
    {
        $maxProduto = (int) DB::table('precos')->max('produto_id');
        $passo = 20000;

        for ($inicio = 1; $inicio <= $maxProduto; $inicio += $passo) {
            DB::statement('
                UPDATE precos p
                SET preco_anterior = ult.anterior_preco,
                    data_anterior  = ult.anterior_data
                FROM (
                    SELECT preco_id,
                           LAG(preco) OVER w AS anterior_preco,
                           LAG(data)  OVER w AS anterior_data
                    FROM precos
                    WHERE produto_id BETWEEN ? AND ?
                    WINDOW w AS (PARTITION BY farmacia_id, produto_id ORDER BY preco_id)
                ) ult
                WHERE ult.preco_id = p.preco_id
                  AND (p.preco_anterior IS DISTINCT FROM ult.anterior_preco
                       OR p.data_anterior IS DISTINCT FROM ult.anterior_data)
            ', [$inicio, $inicio + $passo - 1]);
        }
    }
}
