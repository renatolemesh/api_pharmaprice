<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fecha os tres dominios que a migration de `url_base` deixou nulos.
     *
     * La eles ficaram de fora porque os links vinham do farmaindex e o dominio
     * nao dava para deduzir com seguranca. Agora da: a Panvel e o Preco Popular
     * passaram a ser coletados no site proprio, e o link da Pague Menos foi
     * conferido na resposta do farmaindex.
     *
     * So preenche o que estiver nulo - se alguem ja ajustou a mao, a mao ganha.
     */
    public function up(): void
    {
        $dominios = [
            'PP'          => 'https://www.precopopular.com.br',
            'Panvel'      => 'https://www.panvel.com',
            'Pague menos' => 'https://www.paguemenos.com.br',
        ];

        foreach ($dominios as $nome => $url) {
            DB::table('farmacias')
                ->where('nome_farmacia', $nome)
                ->whereNull('url_base')
                ->update(['url_base' => $url]);
        }
    }

    public function down(): void
    {
        DB::table('farmacias')
            ->whereIn('nome_farmacia', ['PP', 'Panvel', 'Pague menos'])
            ->update(['url_base' => null]);
    }
};
