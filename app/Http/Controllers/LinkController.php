<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Link;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class LinkController extends Controller
{
    public function index(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');

        if (!$farmaciaId) {
            return response()->json(['error' => 'farmacia_id is required'], 400);
        }

        // Tenta recuperar os links do cache
        $links = Cache::remember("links_farmacia_{$farmaciaId}", 60, function () use ($farmaciaId) {
            return Link::where('farmacia_id', $farmaciaId)->get();
        });

        return response()->json($links);
    }
    
    public function destroy(Request $request)
    {
        $validatedData = $request->validate([
            'link' => 'required|string',
            'farmacia_id' => 'nullable|integer',
        ]);

        $query = Link::where('link', $validatedData['link']);

        // Sem filtrar por farmacia, remover o link de uma farmacia apagava a
        // fila de qualquer outra que tivesse o mesmo caminho - e caminhos
        // curtos como /aas-infantil-30-comprimidos se repetem entre redes.
        if (!empty($validatedData['farmacia_id'])) {
            $query->where('farmacia_id', $validatedData['farmacia_id']);
            Cache::forget("links_farmacia_{$validatedData['farmacia_id']}");
        }

        $removidos = $query->delete();

        return response()->json([
            'message' => 'Link removido com sucesso',
            'removidos' => $removidos,
        ], 200);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'farmacia_id' => 'required|integer|exists:farmacias,farmacia_id',
            'link' => 'required',
        ]);

        // Verificar se o link já existe
        $linkExistente = Link::where('farmacia_id', $validatedData['farmacia_id'])
            ->where('link', $validatedData['link'])
            ->first();

        if ($linkExistente) {
            return response()->json(['message' => 'link já existe', 'link' => $linkExistente], 409);
        }

        $link = Link::create($validatedData);

        return response()->json($link, 201);
    }
}
