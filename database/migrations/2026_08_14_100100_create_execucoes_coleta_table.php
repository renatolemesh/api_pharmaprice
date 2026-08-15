<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma linha por execucao de script de coleta.
     *
     * E o que permite notar que "o Unipreco coletou 0 itens hoje" no dia
     * seguinte, e nao seis meses depois. Tambem serve de trava de seguranca:
     * produtos so sao desativados por obsolescencia se a farmacia deles
     * comprovadamente rodou uma coleta recente.
     */
    public function up(): void
    {
        Schema::create('execucoes_coleta', function (Blueprint $table) {
            $table->id('execucao_id');
            $table->unsignedBigInteger('farmacia_id');
            $table->string('script', 50)->nullable();
            $table->timestamp('iniciado_em')->nullable();
            $table->timestamp('finalizado_em')->nullable();
            $table->unsignedInteger('itens_vistos')->default(0);
            $table->unsignedInteger('itens_com_preco')->default(0);
            $table->unsignedInteger('precos_alterados')->default(0);
            $table->unsignedInteger('links_atualizados')->default(0);
            $table->unsignedInteger('skus_atualizados')->default(0);
            $table->unsignedInteger('nao_resolvidos')->default(0);
            $table->unsignedInteger('erros')->default(0);
            $table->string('status', 20)->default('rodando');
            $table->text('observacao')->nullable();

            $table->foreign('farmacia_id')->references('farmacia_id')->on('farmacias')->onDelete('cascade');
            $table->index(['farmacia_id', 'status', 'finalizado_em'], 'idx_execucoes_farmacia_status');
            $table->index('iniciado_em', 'idx_execucoes_iniciado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execucoes_coleta');
    }
};
