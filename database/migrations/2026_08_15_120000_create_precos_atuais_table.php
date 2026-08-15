<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preco atual de cada par (farmacia, produto), em tabela de verdade.
     *
     * Ate aqui essa pergunta era respondida pela `latest_precos_view`, que a
     * cada requisicao fazia GROUP BY sobre 1,86 milhao de linhas de `precos` so
     * pra descobrir qual e o MAX(preco_id) de cada par. Medido nesta base:
     * 7,1s para um COUNT(*) na view sozinha, e 4,9s + 4,9s para o COUNT e o
     * SELECT da busca por descricao — os dois, porque o paginate() do Laravel
     * roda um COUNT antes da consulta.
     *
     * O dado nao precisava ser recalculado: quem insere preco ja sabe, no
     * momento em que insere, qual passou a ser o atual. `precos` continua sendo
     * o log de alteracoes; esta tabela e a projecao do estado presente.
     *
     * Quem escreve: ColetaController (heartbeat do scraper) e PrecoController
     * (POST /precos). Se as duas divergirem por qualquer motivo,
     * `php artisan precos:reconciliar` reconstroi a partir de `precos`.
     */
    public function up(): void
    {
        Schema::create('precos_atuais', function (Blueprint $table) {
            $table->unsignedBigInteger('farmacia_id');
            $table->unsignedBigInteger('produto_id');
            $table->decimal('preco', 8, 2);
            $table->date('data')->nullable();
            // Aponta pra linha de `precos` que originou este valor: e o que
            // permite conferir a projecao contra o log sem adivinhacao.
            $table->unsignedBigInteger('preco_id')->nullable();
            $table->timestamp('atualizado_em')->nullable();

            $table->primary(['farmacia_id', 'produto_id']);
            // A busca filtra por descricao em `produtos` e so depois cai aqui
            // pelo produto_id — sem este indice o join vira varredura.
            $table->index('produto_id', 'idx_precos_atuais_produto');
            $table->index('preco', 'idx_precos_atuais_preco');

            $table->foreign('farmacia_id')->references('farmacia_id')->on('farmacias')->onDelete('cascade');
            $table->foreign('produto_id')->references('produto_id')->on('produtos')->onDelete('cascade');
        });

        // Carga inicial: mesma definicao da view, rodada uma vez em vez de a
        // cada requisicao. Pares com farmacia ou produto nulo ficam de fora —
        // nao teriam chave primaria e nao respondem a pergunta de ninguem.
        DB::statement('
            INSERT INTO precos_atuais (farmacia_id, produto_id, preco, data, preco_id, atualizado_em)
            SELECT p.farmacia_id, p.produto_id, p.preco, p.data, p.preco_id, NOW()
            FROM precos p
            INNER JOIN (
                SELECT produto_id, farmacia_id, MAX(preco_id) AS max_id
                FROM precos
                WHERE farmacia_id IS NOT NULL AND produto_id IS NOT NULL
                GROUP BY produto_id, farmacia_id
            ) ult ON p.preco_id = ult.max_id
            WHERE p.preco IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('precos_atuais');
    }
};
