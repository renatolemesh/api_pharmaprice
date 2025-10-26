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
        DB::statement('
            CREATE VIEW latest_precos_view AS
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
