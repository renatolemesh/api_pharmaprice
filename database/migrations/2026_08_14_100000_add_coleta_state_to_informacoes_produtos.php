<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado de coleta por par (farmacia, produto).
     *
     * Ate aqui a unica evidencia de que um produto continuava vivo era a data
     * do ultimo preco - que so muda quando o preco muda. Preco estavel e
     * produto morto eram indistinguiveis. Estas colunas separam as duas coisas.
     */
    public function up(): void
    {
        Schema::table('informacoes_produtos', function (Blueprint $table) {
            $table->boolean('ativo')->default(true);
            $table->timestamp('ultima_coleta_em')->nullable();
            $table->timestamp('ultima_tentativa_em')->nullable();
            $table->unsignedInteger('falhas_consecutivas')->default(0);
            $table->string('ultimo_status', 30)->nullable();
            $table->timestamp('desativado_em')->nullable();
        });

        Schema::table('informacoes_produtos', function (Blueprint $table) {
            $table->index(['ativo', 'ultima_coleta_em'], 'idx_ip_ativo_coleta');
            $table->index(['farmacia_id', 'ultima_coleta_em'], 'idx_ip_farmacia_coleta');
            // Resolucao de produto por sku/link no heartbeat de coleta: sem estes
            // indices cada lote viraria full scan da tabela.
            $table->index(['farmacia_id', 'sku'], 'idx_ip_farmacia_sku');
            $table->index(['farmacia_id', 'link'], 'idx_ip_farmacia_link');
        });

        // Sem backfill, de proposito.
        //
        // A unica data disponivel para semear seria MAX(precos.data), que e a
        // data da ultima MUDANCA de preco - nao de coleta. Produto de preco
        // estavel apareceria como abandonado ha meses, que e exatamente a
        // ambiguidade que estas colunas existem para eliminar. Semear com ela
        // seria fabricar um dado errado e chamar de historico.
        //
        // `ultima_coleta_em` fica NULL ate a primeira coleta real reportar. O
        // comando de desativacao trata NULL como "ainda nao observado" e so
        // passa a considera-lo obsoleto depois que a farmacia acumular a
        // janela inteira de dias de coleta de verdade.
    }

    public function down(): void
    {
        Schema::table('informacoes_produtos', function (Blueprint $table) {
            $table->dropIndex('idx_ip_ativo_coleta');
            $table->dropIndex('idx_ip_farmacia_coleta');
            $table->dropIndex('idx_ip_farmacia_sku');
            $table->dropIndex('idx_ip_farmacia_link');
        });

        Schema::table('informacoes_produtos', function (Blueprint $table) {
            $table->dropColumn([
                'ativo',
                'ultima_coleta_em',
                'ultima_tentativa_em',
                'falhas_consecutivas',
                'ultimo_status',
                'desativado_em',
            ]);
        });
    }
};
