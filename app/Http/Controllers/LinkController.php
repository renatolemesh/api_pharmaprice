<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Link;

class LinkController extends Controller
{
    public function index(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');

        if (!$farmaciaId) {
            return response()->json(['error' => 'farmacia_id is required'], 400);
        }

        $links = Link::where('farmacia_id', $farmaciaId)->get();

        return response()->json($links);
    }
    
    public function destroy(Request $request)
    {
        $validatedData = $request->validate([
            'link' => 'required|string',
        ]);

        Link::where('link', $validatedData['link'])->delete();

        return response()->json(['message' => 'Link removido com sucesso'], 200);
    }
}
