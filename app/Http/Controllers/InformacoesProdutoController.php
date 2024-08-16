<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\InformacoesProduto;

class InformacoesProdutoController extends Controller
{
    public function index(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');
        $link = $request->query('link');
        $sku = $request->query('sku');

        // Verifique se pelo menos farmacia_id ou link ou sku está presente nos parâmetros da requisição
        if (!$farmaciaId && !$link && !$sku) {
            return response()->json(['error' => 'At least farmacia_id, link, or sku is required'], 400);
        }

        $query = InformacoesProduto::query();

        if ($farmaciaId) {
            $query->where('farmacia_id', $farmaciaId);
        }

        if ($link) {
            $query->where('link', $link);
        }

        if ($sku) {
            $query->where('sku', $sku);
        }

        $informacoes_produto = $query->get();

        return response()->json($informacoes_produto);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'farmacia_id' => 'required|integer|exists:farmacias,farmacia_id',
            'produto_id' => 'required|numeric|exists:produtos,produto_id',
            'link' => 'required|string|unique:informacoes_produtos,link',
            'sku' => 'nullable|numeric',
        ]);

        $informacaoProduto = InformacoesProduto::create($validatedData);

        return response()->json($informacaoProduto, 201);
    }
}
