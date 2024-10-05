<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class DescricaoController extends Controller
{
    public function index(Request $request)
    {
        // Obtém a consulta da requisição, se existir
        $query = $request->query('descricao');

        // Define a chave de cache
        $cacheKey = 'produtos_descricao_' . md5($query);

        // Tenta obter os dados do cache, ou executa a consulta e armazena no cache
        $resultados = Cache::remember($cacheKey, 60 * 24, function () use ($query) {
            return DB::table('produtos')
                ->select('descricao')
                ->when($query, function ($queryBuilder) use ($query) {
                    return $queryBuilder->where('descricao', 'like', "%{$query}%");
                })
                ->get();
        });

        return response()->json($resultados);
    }
}