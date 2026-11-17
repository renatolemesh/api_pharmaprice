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

        // Com `incluir_grupos`, o texto vale tambem como nome de grupo:
        // "gripe" traz o que tem gripe na descricao E os antigripais, que na
        // maioria nao tem (Resfenol, Naldecon, Coristina).
        $codigos = $request->query->has('incluir_grupos') ? GrupoCmed::codigosPara($descricao) : [];

        $porTexto = function (Builder $q, string $alias) use ($similar, $descricao) {
            $similar
                ? BuscaSimilar::filtrar($q, $alias, $descricao)
                : self::contem($q, $alias, $descricao);
        };

        if (!$codigos) {
            $porTexto($query, 'prod');
            return;
        }

        // Uniao dos dois conjuntos de produto, juntada como tabela derivada.
        // O caminho obvio - `texto OR classe IN (...)` - impede o banco de usar
        // o indice de qualquer dos dois lados e varre a tabela inteira.
        $porDescricao = DB::table('produtos as pt')->select('pt.produto_id');
        $porTexto($porDescricao, 'pt');

        $alvo = $porDescricao->union(
            DB::table('cmed_medicamentos as m')
                ->join('produtos as pg', 'pg.EAN', '=', 'm.ean')
                ->whereIn('m.classe_codigo', $codigos)
                ->select('pg.produto_id')
        );

        $query->joinSub($alvo, 'alvo', 'alvo.produto_id', '=', 'prod.produto_id');
    }

    /**
     * Busca por trecho, sem diferenciar maiuscula, acento nem pontuacao:
     * "dipirona sodica" acha "DIPIRONA SÓDICA", "500mg" acha "500 mg".
     *
     * O MySQL fazia as duas primeiras sozinho, pela collation
     * utf8mb4_unicode_ci; no Postgres o LIKE diferencia tudo, entao a
     * comparacao e entre as formas normalizadas. O indice de trigramas em
     * `texto_busca` atende o LIKE com % dos dois lados, que no MySQL era
     * varredura da tabela.
     *
     * @param string $alias alias de `produtos` na consulta
     */
    public static function contem(Builder $query, string $alias, string $texto): void
    {
        // So letras, numeros e espacos: os curingas % e _ que alguem digite
        // ja sairam na normalizacao.
        $termo = BuscaSimilar::normalizar($texto);

        $query->where("{$alias}.texto_busca", 'like', '%' . $termo . '%');
    }
}
