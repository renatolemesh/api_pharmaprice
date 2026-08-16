<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Mesmo piso do ColetaController::PRECO_MINIMO. */
    private const PRECO_MINIMO = 0.02;

    /**
     * Desativa os pares cujo preco atual e baixo demais para ser preco.
     *
     * O piso de entrada era zero, entao R$ 0,01 passava como se fosse preco.
     * Na base eram 25 pares, todos da mesma rede e quase todos jardinagem
     * (sementes e adubo Isla) — itens que o site lista sem preco e o scraper le
     * como um centavo. A partir de agora o ColetaController barra na entrada;
     * esta migration cuida do que ja estava gravado.
     *
     * `ativo = 0` e nao apagar: o historico em `precos` continua sendo
     * historico, e o dia em que a rede publicar um preco de verdade a propria
     * coleta reativa o par. Apagar perderia a informacao de que o produto
     * existe naquela farmacia.
     *
     * `desativado_em` fica com a data de agora e `ultimo_status` diz o motivo,
     * para que isto nao vire um desativado sem explicacao daqui a seis meses.
     */
    public function up(): void
    {
        DB::statement('
            UPDATE informacoes_produtos ip
            JOIN precos_atuais pa
              ON pa.farmacia_id = ip.farmacia_id AND pa.produto_id = ip.produto_id
            SET ip.ativo         = 0,
                ip.desativado_em = NOW(),
                ip.ultimo_status = ?
            WHERE pa.preco < ? AND ip.ativo = 1
        ', ['preco_invalido', self::PRECO_MINIMO]);
    }

    /**
     * Reativa so o que esta migration desativou — identificado pelo status que
     * ela gravou. Reativar tudo que tem preco baixo devolveria ao ar produtos
     * que foram desativados por outro motivo e por acaso tambem estao baratos.
     */
    public function down(): void
    {
        DB::statement('
            UPDATE informacoes_produtos ip
            JOIN precos_atuais pa
              ON pa.farmacia_id = ip.farmacia_id AND pa.produto_id = ip.produto_id
            SET ip.ativo         = 1,
                ip.desativado_em = NULL
            WHERE pa.preco < ? AND ip.ativo = 0 AND ip.ultimo_status = ?
        ', [self::PRECO_MINIMO, 'preco_invalido']);
    }
};
