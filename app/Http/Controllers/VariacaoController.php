<?php

namespace App\Http\Controllers;

use App\Support\VariacaoPreco;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Aumentos e reducoes de preco no periodo, cada um com a variacao sobre o
 * preco que ele substituiu. A regra de quais linhas entram mora em
 * VariacaoPreco, compartilhada com o export do ReportController.
 */
class VariacaoController extends Controller
{
    private const POR_PAGINA = 50;

    public function index(Request $request)
    {
        $validator = VariacaoPreco::validador($request, ['page' => 'nullable|integer|min:1']);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $filtros = VariacaoPreco::filtros($request);
        $pagina = (int) $request->query('page', 1);

        try {
            $resumo = VariacaoPreco::resumo($filtros);

            $total = match ($filtros['tipo']) {
                'aumento' => $resumo['aumentos'],
                'reducao' => $resumo['reducoes'],
                default   => $resumo['aumentos'] + $resumo['reducoes'],
            };

            $linhas = $total > 0
                ? VariacaoPreco::pagina($filtros, $pagina, self::POR_PAGINA)->all()
                : [];
        } catch (\Exception $e) {
            Log::error('Erro no relatorio de variacoes', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Erro interno do servidor'], 500);
        }

        return response()->json([
            'data'         => $linhas,
            'current_page' => $pagina,
            'last_page'    => max(1, (int) ceil($total / self::POR_PAGINA)),
            'per_page'     => self::POR_PAGINA,
            'total'        => $total,
            // O periodo volta resolvido: sem datas na requisicao, a tela precisa
            // saber quais dias o padrao cobriu para dizer isso a quem le.
            'resumo'       => $resumo + [
                'inicio' => $filtros['inicio'],
                'fim'    => $filtros['fim'],
            ],
        ]);
    }
}
