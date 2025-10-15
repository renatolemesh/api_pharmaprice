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
        Schema::table('precos', function (Blueprint $table) {
            $table->index(['farmacia_id', 'produto_id', 'data'], 'idx_precos_farmacia_produto_data');
            $table->index(['produto_id', 'farmacia_id', 'data'], 'idx_precos_produto_farmacia_data');
            $table->index('data', 'idx_precos_data');
        });

        // Only add if produto_id is not already the primary key
        // Since it's id('produto_id'), it's already indexed, so you can skip this
        // Schema::table('produtos', function (Blueprint $table) {
        //     $table->index('produto_id');
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('precos', function (Blueprint $table) {
            $table->dropIndex('idx_precos_farmacia_produto_data');
            $table->dropIndex('idx_precos_produto_farmacia_data');
            $table->dropIndex('idx_precos_data');
        });

        // If you added the produtos index, drop it here
        // Schema::table('produtos', function (Blueprint $table) {
        //     $table->dropIndex(['produto_id']);
        // });
    }
};
