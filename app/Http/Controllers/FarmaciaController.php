<?php

namespace App\Http\Controllers;

use App\Models\Farmacia;
use Illuminate\Support\Facades\Cache;

/**
 * Lista das farmacias.
 *
 * Existe para quem consome parar de manter mapa fixo de id -> nome e
 * id -> dominio. O front tinha os dois escritos a mao, e foi assim que o link
 * do Unipreco continuou apontando para farmaciasunipreco.com.br meses depois de
 * a coleta ter migrado para o marketplace: a base sabia, o front nao.
 *
 * Muda raramente, entao vai com cache - e o `/api/precos` ja devolve `url_base`
 * junto de cada linha, entao na tela de resultado nem precisa desta chamada.
 */
class FarmaciaController extends Controller
{
    public function index()
    {
        $farmacias = Cache::remember('farmacias_lista', 3600, function () {
            return Farmacia::orderBy('farmacia_id')
                ->get(['farmacia_id', 'nome_farmacia', 'url_base']);
        });

        return response()->json($farmacias);
    }
}
