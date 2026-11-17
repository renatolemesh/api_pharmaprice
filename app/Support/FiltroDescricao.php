<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Filtro por descricao, igual para a busca e para a exportacao.
 *
 * Mora aqui, e nao em cada controller, porque a tela e a planilha exportada
 * tem que trazer os mesmos produtos. Quando cada uma tinha o seu LIKE, uma
 * melhoria na busca deixava o Excel para tras sem ninguem perceber.
 *
 * A consulta recebida precisa ter `produtos` com o alias `prod`.
 *
 * Parametros da requisicao que ele le:
 *   modo=similar     casa por semelhanca ("prot sol" acha "Protetor Solar")
 *   incluir_grupos   soma o grupo da CMED ("gripe" acha Resfenol)
 */
class FiltroDescricao
{
    public static function aplicar(Builder $query, string $descricao, Request $request): void
    {
        $similar = $request->query('modo') === 'similar';
        $incluirGrupos = $request->query->has('incluir_grupos');

        // Com `incluir_grupos`, o texto vale tambem como nome de grupo:
        // "gripe" traz o que tem gripe na descricao E os antigripais, que na
        // maioria nao tem (Resfenol, Naldecon, Coristina).
        $codigos = $incluirGrupos ? GrupoCmed::codigosPara($descricao) : [];

        // null quando o indice nao responde: cai no LIKE abaixo, e a busca fica
        // mais estreita em vez de quebrar.
        $similares = $similar ? BuscaSimilar::produtoIds($descricao) : null;

        if ($similares !== null) {
            // O indice esta em outro banco, entao o que volta e uma lista de
            // IDs (no maximo BuscaSimilar::LIMITE_PRODUTOS). Os do grupo entram
            // na mesma lista, num IN so.
            if ($codigos) {
                $similares = array_merge($similares, self::idsDosGrupos($codigos)->pluck('produto_id')->all());
            }

            $query->whereIntegerInRaw('prod.produto_id', array_unique($similares));
            return;
        }

        if ($codigos) {
            // Uniao dos dois conjuntos de produto, juntada como tabela
            // derivada. O caminho obvio - `descricao LIKE ... OR
            // cm.classe_codigo IN (...)` - impede o MySQL de usar qualquer dos
            // dois lados como filtro e varre tudo: 1s para "gripe". Assim custa
            // o mesmo que o LIKE sozinho (56ms x 62ms, medido).
            $alvo = DB::table('produtos')
                ->select('produto_id')
                ->where('descricao', 'like', '%' . $descricao . '%')
                ->union(self::idsDosGrupos($codigos));

            $query->joinSub($alvo, 'alvo', 'alvo.produto_id', '=', 'prod.produto_id');
            return;
        }

        $query->where('prod.descricao', 'like', '%' . $descricao . '%');
    }

    private static function idsDosGrupos(array $codigos): Builder
    {
        return DB::table('cmed_medicamentos as m')
            ->join('produtos as p2', 'p2.EAN', '=', 'm.ean')
            ->whereIn('m.classe_codigo', $codigos)
            ->select('p2.produto_id');
    }
}
