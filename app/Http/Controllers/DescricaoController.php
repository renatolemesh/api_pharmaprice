<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class DescricaoController extends Controller
{
    public function index(Request $request)
    {
        // Obtém a consulta da requisição, se existir
        $query = $request->query('descricao');

        // Inicia a consulta ao banco de dados
        $resultados = DB::table('produtos')
            ->select('descricao')
            ->when($query, function ($queryBuilder) use ($query) {
                return $queryBuilder->where('descricao', 'like', "%{$query}%");
            })
            ->get();

        return response()->json($resultados);
    }
}
