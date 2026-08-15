<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Projecao de `precos` em `precos_atuais`.
 *
 * A regra que sustenta a tabela: a projecao e sempre derivada do log, nunca de
 * valores que a aplicacao tinha em memoria. Quem acabou de inserir em `precos`
 * informa quais pares (farmacia, produto) mexeu, e daqui sai um unico
 * INSERT ... SELECT que releva o MAX(preco_id) desses pares — a mesma
 * definicao da antiga `latest_precos_view`, so que restrita ao punhado de
 * linhas que mudou em vez da tabela inteira.
 *
 * Isso importa porque elimina a classe de bug mais provavel numa tabela
 * derivada: aplicacao e log discordarem. Se o insert em `precos` e a projecao
 * rodam na mesma transacao, ou os dois valem ou nenhum vale.
 */
class PrecoAtual
{
    /** Pares por statement. Acima disso o IN de tuplas fica grande demais. */
    private const LOTE = 500;

    /**
     * @param  array<int, array{farmacia_id: int, produto_id: int}>  $pares
     * @return int linhas de `precos_atuais` inseridas ou atualizadas
     */
    public static function projetar(array $pares): int
    {
        $unicos = [];
        foreach ($pares as $par) {
            $farmaciaId = $par['farmacia_id'] ?? null;
            $produtoId  = $par['produto_id'] ?? null;
            if ($farmaciaId === null || $produtoId === null) {
                continue;
            }
            $unicos["{$farmaciaId}-{$produtoId}"] = [(int) $farmaciaId, (int) $produtoId];
        }

        if (!$unicos) {
            return 0;
        }

        $afetadas = 0;

        foreach (array_chunk(array_values($unicos), self::LOTE) as $lote) {
            $tuplas = implode(',', array_fill(0, count($lote), '(?,?)'));
            $bind = [];
            foreach ($lote as [$farmaciaId, $produtoId]) {
                $bind[] = $farmaciaId;
                $bind[] = $produtoId;
            }

            $afetadas += DB::affectingStatement("
                INSERT INTO precos_atuais
                    (farmacia_id, produto_id, preco, data, preco_id, atualizado_em)
                SELECT p.farmacia_id, p.produto_id, p.preco, p.data, p.preco_id, NOW()
                FROM precos p
                INNER JOIN (
                    SELECT farmacia_id, produto_id, MAX(preco_id) AS max_id
                    FROM precos
                    WHERE (farmacia_id, produto_id) IN ({$tuplas})
                    GROUP BY farmacia_id, produto_id
                ) ult ON p.preco_id = ult.max_id
                WHERE p.preco IS NOT NULL
                ON DUPLICATE KEY UPDATE
                    preco         = VALUES(preco),
                    data          = VALUES(data),
                    preco_id      = VALUES(preco_id),
                    atualizado_em = VALUES(atualizado_em)
            ", $bind);
        }

        return $afetadas;
    }

    /**
     * Preco atual dos produtos de uma farmacia, para comparar antes de inserir.
     *
     * Substitui a leitura da `latest_precos_view` que o heartbeat de coleta
     * fazia a cada lote — era a mesma consulta cara, so que no caminho da
     * escrita em vez do da leitura.
     *
     * @return \Illuminate\Support\Collection preco indexado por produto_id
     */
    public static function daFarmacia(int $farmaciaId, array $produtoIds)
    {
        if (!$produtoIds) {
            return collect();
        }

        return DB::table('precos_atuais')
            ->where('farmacia_id', $farmaciaId)
            ->whereIn('produto_id', $produtoIds)
            ->pluck('preco', 'produto_id');
    }
}
