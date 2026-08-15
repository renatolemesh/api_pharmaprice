<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // OR REPLACE: em producao a view foi criada a mao e a migration nunca
        // foi registrada, entao um CREATE puro aborta com "table already
        // exists" e leva junto as migrations seguintes.
        DB::statement('
            CREATE OR REPLACE VIEW latest_precos_view AS
            SELECT p.*
            FROM precos p
            INNER JOIN (
                SELECT produto_id, farmacia_id, MAX(preco_id) as max_id
                FROM precos
                GROUP BY produto_id, farmacia_id
            ) latest ON p.preco_id = latest.max_id
        ');
    }

    public function down()
    {
        DB::statement('DROP VIEW IF EXISTS latest_precos_view');
    }
};
