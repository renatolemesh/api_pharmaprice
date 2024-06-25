<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PrecoController extends Controller
{
    public function consultar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ean' => 'nullable|string|max:15',
            'descricao' => 'nullable|string|max:255',
            'farmacia' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $ean = $request->query('ean');
        $descricao = $request->query('descricao');
        $farmacia = $request->query('farmacia');

        // Decodifica '+' para ' ' caso esteja presente na descrição
        if ($descricao) {
            $descricao = str_replace('+', ' ', $descricao);
        }

        $query = DB::table('precos')
            ->join('produtos', 'precos.produto_id', '=', 'produtos.produto_id')
            ->join('farmacias', 'precos.farmacia_id', '=', 'farmacias.farmacia_id')
            ->select('produtos.descricao', 'produtos.EAN', 'farmacias.nome_farmacia', 'precos.preco', 'precos.data');

        // Filtro por EAN ou descricao
        if ($ean) {
            $query->where('produtos.EAN', $ean);
        } elseif ($descricao) {
            $query->where('produtos.descricao', 'like', '%' . $descricao . '%');
        }

        if ($farmacia) {
            $query->where('precos.farmacia_id', $farmacia);
        }

        // Subconsulta para obter os preços mais recentes
        $query->whereIn('precos.preco_id', function ($subquery) {
            $subquery->select(DB::raw('MAX(preco_id)'))
                     ->from('precos')
                     ->groupBy('produto_id', 'farmacia_id');
        });

        // Ordenação por preço do menor para o maior
        $query->orderBy('precos.preco', 'asc');

        // Paginação com 100 itens por página
        $resultados = $query->paginate(100);

        if ($resultados->isEmpty()) {
            return response()->json(['message' => 'Nenhum resultado encontrado.'], 404);
        }

        return response()->json([
            'data' => $resultados->items(),
            'current_page' => $resultados->currentPage(),
            'last_page' => $resultados->lastPage(),
            'per_page' => $resultados->perPage(),
            'total' => $resultados->total()
        ]);
    }
}
