<?php

namespace App\Http\Controllers;

use App\Support\GrupoCmed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GrupoController extends Controller
{
    /**
     * Grupos de medicamentos presentes na base.
     *
     *   GET /api/grupos            todos, do maior para o menor
     *   GET /api/grupos?q=gripe    so os que o texto alcanca
     *
     * So entram grupos com pelo menos um produto cadastrado: a CMED tem 541
     * classes, e um quarto delas e de uso hospitalar que nenhuma farmacia
     * coletada vende. Oferecer um grupo que leva a zero resultados e pior do
     * que nao oferecer.
     */
    public function index(Request $request)
    {
        $dados = $request->validate([
            'q' => 'nullable|string|max:100',
        ]);

        $texto = trim((string) ($dados['q'] ?? ''));
        $codigos = $texto === '' ? null : GrupoCmed::codigosPara($texto);

        if ($codigos === []) {
            return response()->json([]);
        }

        $grupos = DB::table('cmed_classes as c')
            ->join('cmed_medicamentos as m', 'm.classe_codigo', '=', 'c.codigo')
            ->join('produtos as p', 'p.EAN', '=', 'm.ean')
            ->when($codigos, fn ($query) => $query->whereIn('c.codigo', $codigos))
            ->groupBy('c.codigo', 'c.nome', 'c.termos')
            ->orderByDesc('produtos')
            ->orderBy('c.codigo')
            ->get(['c.codigo', 'c.nome', 'c.termos', DB::raw('COUNT(*) as produtos')]);

        return response()->json($grupos);
    }
}
