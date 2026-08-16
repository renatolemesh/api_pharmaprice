<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Acrescenta o `/p` que faltava nos links da Preco Popular.
     *
     * A PP e VTEX, e a URL de produto do VTEX termina em `/p`. O scraper
     * gravava o caminho sem o sufixo, e o site responde 200 do mesmo jeito -
     * com uma pagina que traz o slug cru no <title> e nenhum produto dentro.
     * Um 404 disfarcado de 200 nao gera erro em lugar nenhum, e foi assim que
     * links quebrados sobreviveram em todos os registros da farmacia:
     *
     *     /agulha-wiltex-0-70x30-descartavel     -> 200, sem produto
     *     /agulha-wiltex-0-70x30-descartavel/p   -> 200, ean 17899780159189
     *
     * Corrigido tambem em `links`, a fila de descoberta: aqueles caminhos tem o
     * mesmo defeito, entao qualquer passada de descoberta da PP bateria no 404
     * disfarcado em todos eles - e, por ser 200, contaria como "pagina sem
     * codigo de barras" e descartaria o link em vez de reclamar.
     *
     * O `NOT LIKE '%/p'` deixa a migration re-executavel e protege registros
     * que ja estejam certos.
     */
    private const FARMACIA_PP = 6;

    public function up(): void
    {
        foreach (['informacoes_produtos', 'links'] as $tabela) {
            DB::table($tabela)
                ->where('farmacia_id', self::FARMACIA_PP)
                ->whereNotNull('link')
                ->where('link', 'not like', '%/p')
                ->update(['link' => DB::raw("CONCAT(link, '/p')")]);
        }
    }

    public function down(): void
    {
        foreach (['informacoes_produtos', 'links'] as $tabela) {
            DB::table($tabela)
                ->where('farmacia_id', self::FARMACIA_PP)
                ->whereNotNull('link')
                ->where('link', 'like', '%/p')
                ->update(['link' => DB::raw("SUBSTRING(link, 1, CHAR_LENGTH(link) - 2)")]);
        }
    }
};
