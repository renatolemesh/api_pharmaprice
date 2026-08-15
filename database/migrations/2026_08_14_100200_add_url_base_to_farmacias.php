<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dominio de cada farmacia.
     *
     * `informacoes_produtos.link` guarda caminho, nao URL completa, e quem
     * consome precisa saber a que dominio concatenar. Isso estava implicito no
     * front, que assumia um dominio fixo por farmacia - e quebrou no Unipreco,
     * cujos links passaram a vir do marketplace (farmaciasapp.com.br) enquanto
     * o front continuava montando farmaciasunipreco.com.br.
     */
    public function up(): void
    {
        Schema::table('farmacias', function (Blueprint $table) {
            $table->string('url_base', 100)->nullable();
        });

        // Preenchidos a partir do dominio que cada scraper efetivamente usa.
        $dominios = [
            'Raia'      => 'https://www.drogaraia.com.br',
            'Nissei'    => 'https://www.farmaciasnissei.com.br',
            'Morifarma' => 'https://www.morifarma.com.br',
            'Callfarma' => 'https://www.callfarma.com.br',
            'Unipreco'  => 'https://www.farmaciasapp.com.br',
        ];

        foreach ($dominios as $nome => $url) {
            DB::table('farmacias')->where('nome_farmacia', $nome)->update(['url_base' => $url]);
        }

        // PP, Panvel e Pague Menos ficam nulos de proposito: os links deles vem
        // do farmaindex apontando para o site proprio de cada rede, e o dominio
        // nao da para deduzir do codigo com seguranca. Preencher a mao.
    }

    public function down(): void
    {
        Schema::table('farmacias', function (Blueprint $table) {
            $table->dropColumn('url_base');
        });
    }
};
