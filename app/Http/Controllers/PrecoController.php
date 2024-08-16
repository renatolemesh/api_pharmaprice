<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Preco;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PrecoController extends Controller
{
    public function consultar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ean' => 'nullable|string|max:15',
            'descricao' => 'nullable|string|max:255',
            'farmacia' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $ean = $request->query('ean');
        $descricao = $request->query('descricao');
        $farmacia = $request->query('farmacia');
        $noPaginate = $request->query->has('no_paginate');

        if ($descricao) {
            $descricao = str_replace('+', ' ', $descricao);
        }

        $farmaciasIds = [];
        if ($farmacia) {
            $farmacia = str_replace('+', ' ', $farmacia);
            $farmaciasIds = explode(' ', $farmacia);
        }

        $query = DB::table('precos')
            ->join('produtos', 'precos.produto_id', '=', 'produtos.produto_id')
            ->join('farmacias', 'precos.farmacia_id', '=', 'farmacias.farmacia_id')
            ->select('produtos.descricao', 'produtos.EAN', 'farmacias.nome_farmacia', 'precos.preco', 'precos.data');

        if ($ean) {
            $query->where('produtos.EAN', $ean);
        } elseif ($descricao) {
            $query->where('produtos.descricao', 'like', '%' . $descricao . '%');
        }

        if (!empty($farmaciasIds)) {
            $query->whereIn('precos.farmacia_id', $farmaciasIds);
        }

        $query->whereIn('precos.preco_id', function ($subquery) {
            $subquery->select(DB::raw('MAX(preco_id)'))
                     ->from('precos')
                     ->groupBy('produto_id', 'farmacia_id');
        });

        $query->orderBy('precos.preco', 'asc');

        if ($noPaginate) {
            $resultados = $query->get();
        } else {
            $resultados = $query->paginate(100);
        }

        if ($resultados->isEmpty()) {
            return response()->json(['message' => 'Nenhum resultado encontrado.'], 404);
        }

        if ($noPaginate) {
            return response()->json(['data' => $resultados]);
        } else {
            return response()->json([
                'data' => $resultados->items(),
                'current_page' => $resultados->currentPage(),
                'last_page' => $resultados->lastPage(),
                'per_page' => $resultados->perPage(),
                'total' => $resultados->total()
            ]);
        }
    }

    // Método para inserir preço
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'farmacia_id' => 'required|integer|exists:farmacias,farmacia_id',
            'produto_id' => 'required|numeric|exists:produtos,produto_id',
            'preco' => 'required|numeric',
            'data' => 'required|date',
        ]);

        // Verificar se já existe um preço semelhante
        $precoExistente = Preco::where('farmacia_id', $validatedData['farmacia_id'])
            ->where('produto_id', $validatedData['produto_id'])
            ->where('preco', '>=', $validatedData['preco'] - 0.01)
            ->where('preco', '<=', $validatedData['preco'] + 0.01)
            ->first();

        if ($precoExistente) {
            return response()->json(['message' => 'Preço semelhante já existe', 'preco_bd' => $precoExistente], 409);
        }

        $preco = Preco::create($validatedData);

        return response()->json($preco, 201);
    }

    public function obterPrecoAtual(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'farmacia_id' => 'required|integer|exists:farmacias,farmacia_id',
            'produto_id' => 'required|integer|exists:produtos,produto_id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $farmaciaId = $request->query('farmacia_id');
        $produtoId = $request->query('produto_id');

        $precoAtual = DB::table('precos')
            ->where('farmacia_id', $farmaciaId)
            ->where('produto_id', $produtoId)
            ->orderBy('data', 'desc')
            ->first();

        if (!$precoAtual) {
            return response()->json(['message' => 'Preço não encontrado.'], 404);
        }

        return response()->json($precoAtual);
    }
}
