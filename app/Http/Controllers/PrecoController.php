<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Preco;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

DB::enableQueryLog();
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
            $descricao = preg_replace('/\s+/', ' ', trim($descricao));
        }

        $farmaciasIds = [];
        if ($farmacia) {
            $farmacia = str_replace('+', ' ', $farmacia);
            $farmaciasIds = explode(' ', $farmacia);
        }
       
        $query = DB::table('precos')
            ->join('produtos', 'precos.produto_id', '=', 'produtos.produto_id')
            ->join('farmacias', 'precos.farmacia_id', '=', 'farmacias.farmacia_id')
            ->select('produtos.descricao', 'produtos.EAN', 'farmacias.nome_farmacia', 'precos.preco', 'precos.data', 'produtos.produto_id');    

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
            'precos' => 'required|array',
            'precos.*.farmacia_id' => 'required|integer|exists:farmacias,farmacia_id',
            'precos.*.produto_id' => 'required|numeric|exists:produtos,produto_id',
            'precos.*.preco' => 'required|numeric',
            'precos.*.data' => 'required|date',
        ]);

        $resultados = [];
        $conflitos = [];

        $farmaciaIds = array_column($validatedData['precos'], 'farmacia_id');
        $produtoIds = array_column($validatedData['precos'], 'produto_id');

        $existingPrices = Preco::whereIn('farmacia_id', $farmaciaIds)
            ->whereIn('produto_id', $produtoIds)
            ->get()
            ->keyBy(function ($item) {
                return $item->farmacia_id . '-' . $item->produto_id;
            });

        $novosPrecos = [];

        foreach ($validatedData['precos'] as $dados) {
            $key = $dados['farmacia_id'] . '-' . $dados['produto_id'];

            if (isset($existingPrices[$key])) {
                $precoExistente = $existingPrices[$key];
                if (abs($precoExistente->preco - $dados['preco']) <= 0.01) {
                    $conflitos[] = [
                        'produto_id' => $dados['produto_id'],
                        'message' => 'Preço semelhante já existe',
                    ];
                    continue;
                }
            }

            $novosPrecos[] = $dados;
        }

        if (!empty($novosPrecos)) {
            try {
                DB::transaction(function () use ($novosPrecos) {
                    Preco::insert($novosPrecos);
                });
                $resultados = $novosPrecos;

                // Limpa o cache para os preços que foram inseridos
                foreach ($novosPrecos as $dados) {
                    $cacheKey = "preco_atual_{$dados['farmacia_id']}_{$dados['produto_id']}";
                    Cache::forget($cacheKey);
                }

            } catch (\Exception $e) {
                $conflitos[] = [
                    'message' => 'Erro ao inserir preços em massa: ' . $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'resultados' => $resultados,
            'conflitos' => $conflitos,
        ], 200);
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

        // Definindo uma chave única para o cache
        $cacheKey = "preco_atual_{$farmaciaId}_{$produtoId}";

        // Tentando obter o preço do cache
        $precoAtual = Cache::remember($cacheKey, 3600, function () use ($farmaciaId, $produtoId) {
            return DB::table('precos')
                ->where('farmacia_id', $farmaciaId)
                ->where('produto_id', $produtoId)
                ->orderBy('data', 'desc')
                ->first();
        });

        if (!$precoAtual) {
            return response()->json(['message' => 'Preço não encontrado.'], 404);
        }

        return response()->json($precoAtual);
    }
}
