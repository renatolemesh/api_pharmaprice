<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Exige o token dos scrapers nas rotas que ESCREVEM.
 *
 * Por que existe: todas as rotas de escrita eram abertas. Qualquer um que
 * lesse o bundle do front ou adivinhasse a URL podia gravar preco, apagar link
 * ou trocar o laboratorio de 196 mil produtos com um PUT em lote.
 *
 * So as escritas: a leitura e o que o front (Vercel) consome sem login, e um
 * token embutido no bundle do navegador nao protegeria nada.
 *
 * Sem COLETA_TOKEN configurado a rota RECUSA, em vez de liberar: esquecer a
 * variavel num servidor novo nao pode reabrir a API em silencio.
 */
class ExigeTokenColeta
{
    public function handle(Request $request, Closure $next)
    {
        $esperado = (string) config('services.coleta.token');
        $recebido = (string) $request->header('X-Api-Token');

        if ($esperado === '' || !hash_equals($esperado, $recebido)) {
            return response()->json(['message' => 'Token de coleta ausente ou invalido.'], 401);
        }

        return $next($request);
    }
}
