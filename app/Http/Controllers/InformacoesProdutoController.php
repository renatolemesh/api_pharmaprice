<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\InformacoesProduto;
use Illuminate\Validation\Rule;

class InformacoesProdutoController extends Controller
{
    public function index(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');
        $produtoId = $request->query('produto_id');
        $link = $request->query('link');
        $sku = $request->query('sku');

        // Verifique se pelo menos farmacia_id ou produto_id ou link ou sku está presente
        if (!$farmaciaId && !$produtoId && !$link && !$sku) {
            return response()->json(['error' => 'At least farmacia_id, produto_id, link, or sku is required'], 400);
        }

        $query = InformacoesProduto::query();

        if ($farmaciaId) {
            $query->where('farmacia_id', $farmaciaId);
        }

        // Sem este filtro, o scraper perguntava "existe info para este produto?"
        // e recebia a resposta de "existe info para este link" - duas perguntas
        // diferentes que davam respostas contraditorias.
        if ($produtoId) {
            $query->where('produto_id', $produtoId);
        }

        if ($link) {
            $query->where('link', $link);
        }

        if ($sku) {
            $query->where('sku', $sku);
        }

        // `?campos=sku,link` devolve so o que foi pedido. Os scrapers da Raia
        // precisam apenas do par (sku, link) para montar a consulta em lote, e
        // a resposta inteira dos 79 mil vinculos dela passa de 23 MB - carregada
        // por rodada so para descartar quase tudo.
        $campos = $this->camposPedidos($request->query('campos'));
        if ($campos) {
            $query->select($campos);
        }

        $informacoes_produto = $query->get();

        return response()->json($informacoes_produto);
    }

    /**
     * Colunas pedidas em `?campos=`, restritas a uma lista fechada.
     *
     * Repassar a string direto para o select deixaria a query aberta a
     * qualquer expressao vinda de fora.
     */
    private function camposPedidos(?string $bruto): array
    {
        if (!$bruto) {
            return [];
        }

        $permitidos = [
            'informacoes_id', 'farmacia_id', 'produto_id', 'link', 'sku',
            'ativo', 'ultima_coleta_em', 'ultima_tentativa_em',
            'falhas_consecutivas', 'ultimo_status', 'desativado_em',
        ];

        $pedidos = array_map('trim', explode(',', $bruto));

        return array_values(array_intersect($pedidos, $permitidos));
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'farmacia_id' => 'required|integer|exists:farmacias,farmacia_id',
            'produto_id' => 'required|numeric|exists:produtos,produto_id',
            'link' => [
                'required',
                'string',
                Rule::unique('informacoes_produtos')->where(function ($query) use ($request) {
                    return $query->where('farmacia_id', $request->farmacia_id);
                }),
            ],  
            'sku' => 'required|numeric',
        ]);

        $informacaoProduto = InformacoesProduto::create($validatedData);

        return response()->json($informacaoProduto, 201);
    }

    /**
     * Cria ou corrige o vinculo (farmacia, produto) -> (link, sku).
     *
     * O `store` acima so insere, e a tabela tem unique(farmacia_id, produto_id):
     * na pratica o link de um produto nascia congelado e nunca mais era
     * corrigido, mesmo depois que a farmacia trocava a URL. Era essa a causa do
     * link que abria a pagina de outro produto.
     */
    public function upsert(Request $request)
    {
        $validatedData = $request->validate([
            'farmacia_id' => 'required|integer|exists:farmacias,farmacia_id',
            'produto_id' => 'required|integer|exists:produtos,produto_id',
            'link' => 'required|string|max:255',
            'sku' => 'required|string|max:50',
        ]);

        // Um mesmo link nao pode responder por dois produtos da mesma farmacia.
        $conflito = InformacoesProduto::where('farmacia_id', $validatedData['farmacia_id'])
            ->where('link', $validatedData['link'])
            ->where('produto_id', '!=', $validatedData['produto_id'])
            ->first();

        if ($conflito) {
            return response()->json([
                'message' => 'Link ja pertence a outro produto desta farmacia',
                'informacao' => $conflito,
            ], 409);
        }

        $informacaoProduto = InformacoesProduto::updateOrCreate(
            [
                'farmacia_id' => $validatedData['farmacia_id'],
                'produto_id' => $validatedData['produto_id'],
            ],
            [
                'link' => $validatedData['link'],
                'sku' => $validatedData['sku'],
            ]
        );

        return response()->json($informacaoProduto, $informacaoProduto->wasRecentlyCreated ? 201 : 200);
    }

    public function indexAll()
    {
        // Busca todas as informações dos produtos
        $informacoes_produto = InformacoesProduto::all();

        return response()->json($informacoes_produto);
    }
    
}
