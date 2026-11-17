<?php

namespace App\Http\Controllers;

use App\Support\BuscaSimilar;
use App\Support\FiltroDescricao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class DescricaoController extends Controller
{
    /**
     * Teto de sugestões devolvidas.
     *
     * Sem ele, `/api/descricoes` sem filtro devolve a tabela inteira — 135 mil
     * descrições, alguns megabytes de JSON. O front já exige três caracteres
     * antes de chamar, mas a proteção precisa morar aqui: quem chama a API
     * direto não herda a regra do componente.
     *
     * Trinta porque a lista é de escolha rápida: quem digitou "dipirona" e
     * recebeu trinta linhas não vai rolar até a centésima — vai digitar mais.
     */
    private const LIMITE = 30;

    public function index(Request $request)
    {
        $query = $request->query('descricao');

        // Mesma regra de /precos?modo=similar: as sugestoes tem que ser as
        // descricoes que a busca vai encontrar, senao a lista promete o que a
        // pesquisa nao entrega.
        if ($request->query('modo') === 'similar' && trim((string) $query) !== '') {
            $similares = Cache::remember(
                'produtos_descricao_similar_' . md5((string) $query),
                60 * 24,
                fn () => BuscaSimilar::descricoes((string) $query, self::LIMITE)
            );

            return response()->json(array_map(fn ($d) => ['descricao' => $d], $similares));
        }

        $cacheKey = 'produtos_descricao_' . md5((string) $query);

        $resultados = Cache::remember($cacheKey, 60 * 24, function () use ($query) {
            return DB::table('produtos as prod')
                /*
                 * `distinct` porque a busca é PELA descrição, não pelo produto.
                 *
                 * Produtos diferentes compartilham o mesmo texto: a Nan Sem
                 * Lactose 400g existe duas vezes, com EAN suíço e europeu, e há
                 * 1.825 descrições repetidas na base — uma delas em 12 linhas.
                 * Cada linha virava uma sugestão, então a lista mostrava o mesmo
                 * texto várias vezes, e todas levavam ao mesmo resultado, porque
                 * o que é enviado na busca é a descrição. Escolher entre doze
                 * opções idênticas não é escolha.
                 */
                ->distinct()
                ->select('prod.descricao')
                ->whereNotNull('prod.descricao')
                // Mesmo LIKE da busca: sem diferenciar maiuscula nem acento.
                ->when($query, fn ($q) => FiltroDescricao::contem($q, 'prod', (string) $query))
                // Ordem estável: sem `order by`, duas chamadas iguais podem
                // devolver recortes diferentes das mesmas 30 linhas, e a
                // sugestão dança embaixo do cursor de quem está digitando.
                ->orderBy('prod.descricao')
                ->limit(self::LIMITE)
                ->get();
        });

        return response()->json($resultados);
    }
}
