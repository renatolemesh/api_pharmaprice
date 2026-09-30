<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Correcao do laboratorio de produtos ja cadastrados, em lote.
 *
 * Por que existe: o laboratorio so era gravado no cadastro, e nunca mais. Um
 * scraper antigo gravou marca de filtro de categoria em massa - 29 mil produtos
 * com "Sensodyne", 26 mil com "Dorflex", Nesina como "FreeStyle Libre" - e nao
 * havia caminho nenhum para consertar. Quem decide o valor certo e o comando
 * `python -m webscrap marcas`, por votacao entre farmacias.
 *
 * Cada item leva o valor `anterior` que o scraper leu. A troca so acontece se o
 * banco ainda tiver exatamente aquele valor: a rodada leva horas, e uma
 * correcao feita a mao nesse meio tempo nao pode ser atropelada por uma leitura
 * velha. Item recusado por isso volta em `divergentes`.
 */
class LaboratorioLoteController extends Controller
{
    private const MAXIMO_ITENS = 1000;

    public function update(Request $request)
    {
        $dados = $request->validate([
            'itens'               => 'required|array|min:1|max:' . self::MAXIMO_ITENS,
            'itens.*.ean'         => 'required|string|max:15',
            'itens.*.laboratorio' => 'required|string|max:50',
            'itens.*.anterior'    => 'present|nullable|string|max:255',
        ]);

        $atualizados = 0;
        $divergentes = [];

        DB::transaction(function () use ($dados, &$atualizados, &$divergentes) {
            foreach ($dados['itens'] as $item) {
                $consulta = DB::table('produtos')->where('EAN', trim($item['ean']));

                // NULL e vazio contam como o mesmo "sem laboratorio".
                if ($item['anterior'] === null || $item['anterior'] === '') {
                    $consulta->where(function ($q) {
                        $q->whereNull('laboratorio')->orWhere('laboratorio', '');
                    });
                } else {
                    $consulta->where('laboratorio', $item['anterior']);
                }

                if ($consulta->update(['laboratorio' => trim($item['laboratorio'])])) {
                    $atualizados++;
                } else {
                    $divergentes[] = $item['ean'];
                }
            }
        });

        return response()->json([
            'recebidos'   => count($dados['itens']),
            'atualizados' => $atualizados,
            'divergentes' => $divergentes,
        ]);
    }
}
