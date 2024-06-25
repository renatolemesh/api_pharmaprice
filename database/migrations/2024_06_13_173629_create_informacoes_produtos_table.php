<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('informacoes_produtos', function (Blueprint $table) {
            $table->id('informacao_id');
            $table->unsignedBigInteger('farmacia_id')->nullable();
            $table->unsignedBigInteger('produto_id')->nullable();
            $table->string('link', 255)->unique()->nullable();
            $table->string('sku', 50)->nullable();
            $table->timestamps();
            $table->foreign('farmacia_id')->references('farmacia_id')->on('farmacias')->onDelete('cascade');
            $table->foreign('produto_id')->references('produto_id')->on('produtos')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('informacoes_produtos');
    }
};
