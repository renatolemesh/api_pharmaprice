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
        Schema::create('precos', function (Blueprint $table) {
            $table->id('preco_id');
            $table->unsignedBigInteger('farmacia_id')->nullable();
            $table->unsignedBigInteger('produto_id')->nullable();
            $table->decimal('preco', 6, 2)->nullable();
            $table->date('data')->nullable();
            $table->foreign('farmacia_id')->references('farmacia_id')->on('farmacias')->onDelete('cascade');
            $table->foreign('produto_id')->references('produto_id')->on('produtos')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('precos');
    }
};
