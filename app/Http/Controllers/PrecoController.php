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
            ->leftJoin('informacoes_produtos', function ($join) {
                $join->on('produtos.produto_id', '=', 'informacoes_produtos.produto_id')
                    ->on('precos.farmacia_id', '=', 'informacoes_produtos.farmacia_id');
            })
            ->select(
                'produtos.descricao',
                'produtos.EAN',
                'farmacias.nome_farmacia',
                'precos.preco',
                'precos.data',
                'produtos.produto_id',
                DB::raw('MAX(informacoes_produtos.link) as link') // Seleciona o link máximo
            )
            ->groupBy('produtos.produto_id', 'farmacias.farmacia_id', 'produtos.descricao', 'produtos.EAN', 'precos.preco', 'precos.data');

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
        // Validação dos dados de entrada
        $validatedData = $request->validate([
            'precos' => 'required|array',
            'precos.*.farmacia_id' => 'required|integer',
            'precos.*.produto_id' => 'required|numeric',
            'precos.*.preco' => 'required|numeric',
            'precos.*.data' => 'required|date',
        ]);

        // Remover duplicatas
        $validatedData['precos'] = array_values(array_unique($validatedData['precos'], SORT_REGULAR));

        $resultados = [];
        $conflitos = [];

        // Agrupar por farmacia_id e produto_id
        $novosPrecos = [];
        foreach ($validatedData['precos'] as $dados) {
            $key = $dados['farmacia_id'] . '-' . $dados['produto_id'];
            if (!isset($novosPrecos[$key])) {
                $novosPrecos[$key] = $dados; // Substitui se já existir
            }
        }

        // Obter os preços existentes mais recentes
        $existingPrices = Preco::select('farmacia_id', 'produto_id', 'preco', 'data')
            ->whereIn('farmacia_id', array_column($novosPrecos, 'farmacia_id'))
            ->whereIn('produto_id', array_column($novosPrecos, 'produto_id'))
            ->whereRaw('(farmacia_id, produto_id, data) IN (
                SELECT farmacia_id, produto_id, MAX(data) 
                FROM precos 
                GROUP BY farmacia_id, produto_id)')
            ->get()
            ->keyBy(function ($item) {
                return $item->farmacia_id . '-' . $item->produto_id;
            });

        // Validar e preparar os novos preços
        $finalPrecos = [];
        foreach ($novosPrecos as $key => $dados) {
            if (isset($existingPrices[$key])) {
                $precoExistente = $existingPrices[$key];
                if (abs($precoExistente->preco - $dados['preco']) <= 0.01) {
                    // Adicionar aos conflitos se o preço for muito semelhante
                    $conflitos[] = [
                        'produto_id' => $dados['produto_id'],
                        'message' => 'Preço semelhante já existe',
                    ];
                    continue;
                }
            }
            // Adicionar aos preços finais se for válido
            $finalPrecos[] = $dados;
        }

        // Inserir novos preços se houver algum válido
        if (!empty($finalPrecos)) {
            try {
                // Transação para garantir atomicidade
                DB::transaction(function () use ($finalPrecos) {
                    Preco::insert($finalPrecos);
                });
                $resultados = $finalPrecos;

                // Limpa o cache para os preços que foram inseridos
                foreach ($finalPrecos as $dados) {
                    $cacheKey = "preco_atual_{$dados['farmacia_id']}_{$dados['produto_id']}";
                    Cache::forget($cacheKey);
                }

            } catch (\Exception $e) {
                // Capturar erros durante a inserção em massa
                $conflitos[] = [
                    'message' => 'Erro ao inserir preços em massa: ' . $e->getMessage(),
                ];
            }
        }

        // Retorna o resultado como resposta JSON
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
