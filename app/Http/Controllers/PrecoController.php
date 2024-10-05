<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Preco;
use Illuminate\Support\Facades\DB;
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
        $logQueries = DB::getQueryLog();
        Log::info($logQueries);
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

            foreach ($validatedData['precos'] as $dados) {
                // Busca o preço mais recente
                $precoExistente = Preco::where('farmacia_id', $dados['farmacia_id'])
                    ->where('produto_id', $dados['produto_id'])
                    ->orderBy('data', 'desc')
                    ->first();

                // Se não há preço existente, podemos inserir
                if (!$precoExistente) {
                    try {
                        $preco = Preco::create($dados);
                        $resultados[] = $preco;
                    } catch (\Exception $e) {
                        $conflitos[] = [
                            'produto_id' => $dados['produto_id'],
                            'message' => 'Erro ao inserir preço: ' . $e->getMessage(),
                        ];
                    }
                    continue; // Pula para o próximo item
                }

                // Compara o novo preço apenas com o preço mais recente
                if (abs($precoExistente->preco - $dados['preco']) <= 0.01) {
                    $conflitos[] = [
                        'produto_id' => $dados['produto_id'],
                        'message' => 'Preço semelhante já existe',
                    ];
                    continue; // Pula para o próximo item
                }

                try {
                    // Criar um novo registro de preço
                    $preco = Preco::create($dados);
                    $resultados[] = $preco;
                } catch (\Exception $e) {
                    $conflitos[] = [
                        'produto_id' => $dados['produto_id'],
                        'message' => 'Erro ao inserir preço: ' . $e->getMessage(),
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
