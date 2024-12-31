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
        DB::enableQueryLog();
        $validator = Validator::make($request->all(), [
            'ean' => 'nullable|string|max:15',
            'descricao' => 'nullable|string|max:255',
            'farmacia' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $ean = $request->query('ean');
        $descricao = $request->query('descricao');
        $farmacia = $request->query('farmacia');
        $noPaginate = $request->query->has('no_paginate');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 100);

        $query = DB::query()
            ->fromSub(function ($query) {
                $query->select('p.*')
                    ->from('precos as p')
                    ->join(DB::raw('(
                        SELECT produto_id, farmacia_id, MAX(preco_id) as max_preco_id
                        FROM precos
                        GROUP BY produto_id, farmacia_id
                    ) as latest'), function($join) {
                        $join->on('p.preco_id', '=', 'latest.max_preco_id');
                    });
            }, 'latest_precos')
            ->join('produtos', 'latest_precos.produto_id', '=', 'produtos.produto_id')
            ->join('farmacias', 'latest_precos.farmacia_id', '=', 'farmacias.farmacia_id')
            ->leftJoin('informacoes_produtos', function ($join) {
                $join->on('produtos.produto_id', '=', 'informacoes_produtos.produto_id')
                    ->on('latest_precos.farmacia_id', '=', 'informacoes_produtos.farmacia_id');
            })
            ->select([
                'produtos.descricao',
                'produtos.EAN',
                'farmacias.nome_farmacia',
                'latest_precos.preco',
                'latest_precos.data',
                'produtos.produto_id',
                'informacoes_produtos.link'
            ]);

        if ($ean) {
            $query->where('produtos.EAN', $ean);
        } elseif ($descricao) {
            $query->where('produtos.descricao', 'like', '%' . $descricao . '%');
        }

        $query->orderBy('latest_precos.preco', 'asc');
        try {
            if ($noPaginate) {
                $resultados = $query->get();
                return response()->json(['data' => $resultados]);
            }

            // Manual pagination
            $total = DB::table(DB::raw("({$query->toSql()}) as sub"))
                ->mergeBindings($query)
                ->count(DB::raw('1'));

            $offset = ($page - 1) * $perPage;
            $resultados = $query->skip($offset)->take($perPage)->get();

            return response()->json([
                'data' => $resultados,
                'current_page' => $page,
                'last_page' => ceil($total / $perPage),
                'per_page' => $perPage,
                'total' => $total
            ]);

        } catch (\Exception $e) {
            Log::error('Erro na execução da consulta', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Erro interno do servidor'], 500);
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
