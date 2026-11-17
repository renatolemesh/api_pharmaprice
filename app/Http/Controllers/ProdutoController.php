<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Produto;

class ProdutoController extends Controller
{
    public function index(Request $request)
    {
        $ean = $request->query('ean');

        if (!$ean) {
            return response()->json(['error' => 'ean is required'], 400);
        }

        $produto = Produto::where('EAN', $ean)->get();

        return response()->json($produto);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'descricao' => 'required|string|max:255',
            'ean' => 'required|numeric|unique:produtos,EAN',
            'laboratorio' => 'nullable|string|max:255',
        ]);

        // A coluna e "EAN"; o MySQL aceitava `ean` por nao diferenciar
        // maiusculas no nome de coluna, o Postgres nao.
        $produto = Produto::create([
            'descricao'   => $validatedData['descricao'],
            'EAN'         => (string) $validatedData['ean'],
            'laboratorio' => $validatedData['laboratorio'] ?? null,
        ]);

        return response()->json($produto, 201);
    }

    public function indexAll()
    {
        // Busca todas as informações dos produtos
        $produtos = Produto::all();

        return response()->json($produtos);
    }
}