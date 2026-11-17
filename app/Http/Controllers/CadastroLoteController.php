<?php

namespace App\Http\Controllers;

use App\Models\InformacoesProduto;
use App\Models\Produto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro de produto novo em lote.
 *
 * Por que existe: o scraper cadastrava produto desconhecido com TRES chamadas
 * por item - GET /produtos?ean=, POST /produtos, PUT /informacoes_produtos.
 * Numa rodada do Preco Popular com 378 produtos novos isso deu 1.134
 * requisicoes e ~20 minutos.
 *
 * E nao era o banco: a consulta por EAN leva 1,3 ms com o indice que ja existe.
 * O custo era o bootstrap do Laravel repetido mil vezes. Por isso a solucao e
 * lote, e nao indice: as mesmas 1.134 chamadas viram duas.
 *
 * Tudo numa transacao por lote, com as mesmas regras do caminho unitario:
 * resolve o produto por EAN (reaproveitando o que ja existe, que e o que
 * deduplica entre farmacias), e so entao grava o vinculo - recusando link que
 * ja responde por outro produto da mesma farmacia.
 */
class CadastroLoteController extends Controller
{
    /** Teto por requisicao. Acima disso o lote vira um problema de memoria. */
    private const MAXIMO_ITENS = 1000;

    public function store(Request $request)
    {
        $dados = $request->validate([
            'farmacia_id'          => 'required|integer|exists:farmacias,farmacia_id',
            'itens'                => 'required|array|min:1|max:' . self::MAXIMO_ITENS,
            'itens.*.ean'          => 'required|string|max:15',
            'itens.*.descricao'    => 'nullable|string|max:255',
            'itens.*.laboratorio'  => 'nullable|string|max:50',
            'itens.*.sku'          => 'required|string|max:50',
            'itens.*.link'         => 'required|string|max:255',
        ]);

        $farmaciaId = (int) $dados['farmacia_id'];
        $recebidos = count($dados['itens']);
        $itens = $this->normalizar($dados['itens']);

        if (!$itens) {
            return response()->json([
                'recebidos' => $recebidos, 'criados' => 0, 'vinculados' => 0,
                'descartados' => $recebidos, 'conflitos' => [], 'resolvidos' => [],
            ]);
        }

        return DB::transaction(function () use ($farmaciaId, $recebidos, $itens) {
            $mapa = $this->resolverProdutos($itens);
            $resultado = $this->gravarVinculos($farmaciaId, $itens, $mapa['por_ean']);

            return response()->json([
                'recebidos'   => $recebidos,
                'criados'     => $mapa['criados'],
                'vinculados'  => $resultado['vinculados'],
                // Tudo que entrou e nao virou vinculo: EAN invalido, EAN
                // repetido na mesma leva e link em conflito. Sem este numero,
                // um lote que descarta metade dos itens se parece com um lote
                // perfeito.
                'descartados' => $recebidos - $resultado['vinculados'],
                'conflitos'   => $resultado['conflitos'],
                'resolvidos'  => $resultado['resolvidos'],
            ]);
        });
    }

    /**
     * Descarta EAN invalido e mantem um item por EAN.
     *
     * Dois SKUs com o mesmo EAN na mesma leva disputariam a mesma linha de
     * `informacoes_produtos` (a chave e farmacia+produto) e o ultimo venceria
     * por sorteio. Fica o primeiro, e o segundo volta na proxima rodada.
     */
    private function normalizar(array $brutos): array
    {
        $itens = [];

        foreach ($brutos as $bruto) {
            $ean = trim((string) $bruto['ean']);

            if (!ctype_digit($ean) || strlen($ean) < 8) {
                continue;
            }
            if (isset($itens[$ean])) {
                continue;
            }

            $itens[$ean] = [
                'ean'         => $ean,
                'descricao'   => $bruto['descricao'] ?? null,
                'laboratorio' => $bruto['laboratorio'] ?? null,
                'sku'         => (string) $bruto['sku'],
                'link'        => $bruto['link'],
            ];
        }

        return $itens;
    }

    /**
     * EAN -> produto_id, criando o que faltar.
     *
     * `insertOrIgnore` em vez de insert: outro scraper pode ter criado o mesmo
     * EAN entre o SELECT e o INSERT, e a coluna e unica. O segundo SELECT
     * devolve o id de quem ganhou a corrida, seja quem for.
     */
    private function resolverProdutos(array $itens): array
    {
        $eans = array_keys($itens);

        $porEan = Produto::whereIn('EAN', $eans)->pluck('produto_id', 'EAN')->all();

        $faltantes = array_diff($eans, array_keys($porEan));
        if (!$faltantes) {
            return ['por_ean' => $porEan, 'criados' => 0];
        }

        $novos = [];
        foreach ($faltantes as $ean) {
            $novos[] = [
                'EAN'         => $ean,
                'descricao'   => $itens[$ean]['descricao'],
                'laboratorio' => $itens[$ean]['laboratorio'],
            ];
        }

        DB::table('produtos')->insertOrIgnore($novos);

        $recem = Produto::whereIn('EAN', $faltantes)->pluck('produto_id', 'EAN')->all();

        return [
            'por_ean' => $porEan + $recem,
            'criados' => count($recem),
        ];
    }

    /**
     * Grava (farmacia, produto) -> (link, sku), recusando link ja usado.
     */
    private function gravarVinculos(int $farmaciaId, array $itens, array $porEan): array
    {
        $produtoIds = array_values($porEan);
        $links = array_column($itens, 'link');

        // Uma consulta so para as duas perguntas: quem ja tem linha (para nao
        // zerar o historico de coleta) e qual link ja pertence a outro produto.
        $existentes = InformacoesProduto::where('farmacia_id', $farmaciaId)
            ->where(function ($q) use ($produtoIds, $links) {
                $q->whereIn('produto_id', $produtoIds)->orWhereIn('link', $links);
            })
            ->get(['produto_id', 'link']);

        $donoDoLink = [];
        foreach ($existentes as $linha) {
            if ($linha->link !== null) {
                $donoDoLink[$linha->link] = (int) $linha->produto_id;
            }
        }

        $linhas = [];
        $conflitos = [];
        $resolvidos = [];

        foreach ($itens as $ean => $item) {
            $produtoId = $porEan[$ean] ?? null;
            if (!$produtoId) {
                continue;
            }

            $dono = $donoDoLink[$item['link']] ?? null;
            if ($dono !== null && $dono !== (int) $produtoId) {
                $conflitos[] = [
                    'ean'   => $ean,
                    'link'  => $item['link'],
                    'motivo' => 'link ja pertence ao produto ' . $dono,
                ];
                continue;
            }

            // Chave da tabela e (farmacia, produto): um produto_id repetido no
            // mesmo lote geraria upsert duplicado na mesma chave.
            $linhas[$produtoId] = [
                'farmacia_id' => $farmaciaId,
                'produto_id'  => $produtoId,
                'link'        => $item['link'],
                'sku'         => $item['sku'],
                'ativo'       => 1,
            ];

            $resolvidos[] = [
                'ean'        => $ean,
                'produto_id' => (int) $produtoId,
                'sku'        => $item['sku'],
                'link'       => $item['link'],
            ];
        }

        if ($linhas) {
            foreach (array_chunk(array_values($linhas), 500) as $lote) {
                // `ultima_coleta_em` fica de fora de proposito: quem carimba
                // coleta e o /api/coletas, e o preco desta rodada ainda vai
                // passar por la. Marcar aqui daria o produto por coletado antes
                // de ter preco.
                InformacoesProduto::upsert(
                    $lote,
                    ['farmacia_id', 'produto_id'],
                    ['link', 'sku', 'ativo']
                );
            }
        }

        return [
            'vinculados' => count($linhas),
            'conflitos'  => $conflitos,
            'resolvidos' => $resolvidos,
        ];
    }
}
