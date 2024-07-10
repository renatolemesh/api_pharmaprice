<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class HistoricoController extends Controller
{
    public function historico(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ean' => 'nullable|string|max:15',
            'descricao' => 'nullable|string|max:255',
            'farmacia' => 'nullable|string',
            'data-inicio' => 'nullable|date',
            'data-fim' => 'nullable|date',
            'page' => 'nullable|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $ean = $request->query('ean');
        $descricao = $request->query('descricao');
        $farmacia = $request->query('farmacia');
        $data_inicio = $request->query('data-inicio');
        $data_fim = $request->query('data-fim');
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

        if ($data_inicio && $data_fim) {
            $query->whereBetween('precos.data', [$data_inicio, $data_fim]);
        } elseif ($data_inicio) {
            $query->where('precos.data', '>=', $data_inicio);
        } elseif ($data_fim) {
            $query->where('precos.data', '<=', $data_fim);
        }

        if ($noPaginate) {
            $resultados = $query->get();
        } else {
            $resultados = $query->paginate(100);
        }

        if ($resultados->isEmpty()) {
            return response()->json(['message' => 'Nenhum resultado encontrado.'], 404);
        }

        $historico = [];
        foreach ($resultados as $resultado) {
            $key = $resultado->descricao . '-' . $resultado->EAN . '-' . $resultado->nome_farmacia;
            if (!isset($historico[$key])) {
                $historico[$key] = [
                    'descricao' => $resultado->descricao,
                    'EAN' => $resultado->EAN,
                    'nome_farmacia' => $resultado->nome_farmacia,
                    'precos' => []
                ];
            }
            $historico[$key]['precos'][] = [
                'preco' => $resultado->preco,
                'data' => $resultado->data
            ];
        }

        if ($noPaginate) {
            return response()->json(['data' => array_values($historico)]);
        } else {
            return response()->json([
                'data' => array_values($historico),
                'current_page' => $resultados->currentPage(),
                'last_page' => $resultados->lastPage(),
                'per_page' => $resultados->perPage(),
                'total' => $resultados->total()
            ]);
        }
    }
}
