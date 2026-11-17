<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lista de precos da CMED/Anvisa, usada aqui pelo que ela diz SOBRE o
     * medicamento, e nao pelo preco: substancia e classe terapeutica por EAN.
     *
     * E o que permite buscar por grupo. "Resfenol" nao tem "gripe" no nome,
     * entao nenhuma busca sobre a descricao o encontra; a classe dele (R5A -
     * antigripais) encontra.
     *
     * As duas tabelas sao uma copia da planilha, reescrita a cada importacao
     * (`php artisan cmed:importar`). Nada em `produtos` e alterado: a ligacao e
     * feita pelo EAN na hora da consulta, entao produto cadastrado depois da
     * importacao ja nasce no grupo certo.
     */
    public function up(): void
    {
        Schema::create('cmed_classes', function (Blueprint $table) {
            // Codigo como a Anvisa publica: "R5A", "N2B2", "J07A1".
            $table->string('codigo', 10)->primary();
            $table->string('nome', 200);
            // Termos leigos, separados por virgula ("gripe, resfriado").
            // Vem de database/data/cmed_termos.php, nao da planilha.
            $table->string('termos', 500)->nullable();
        });

        Schema::create('cmed_medicamentos', function (Blueprint $table) {
            // Mesmo tipo de produtos.EAN, que e por onde as duas se juntam.
            $table->string('ean', 15)->primary();
            $table->string('classe_codigo', 10)->nullable();
            $table->text('substancia')->nullable();
            $table->string('laboratorio', 255)->nullable();
            $table->string('produto', 255)->nullable();
            $table->text('apresentacao')->nullable();

            $table->index('classe_codigo', 'idx_cmed_medicamentos_classe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmed_medicamentos');
        Schema::dropIfExists('cmed_classes');
    }
};
