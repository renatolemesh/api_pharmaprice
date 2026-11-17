<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Produtos que nenhuma coleta bem-sucedida encontra ha mais de 30 dias saem da
// consulta. O proprio comando se recusa a agir sobre farmacia cujo scraper nao
// concluiu coleta na ultima semana - scraper quebrado nao pode zerar catalogo.
Schedule::command('coletas:desativar-obsoletos')
    ->dailyAt('05:00')
    ->withoutOverlapping();

// A Anvisa republica a lista da CMED todo mes, sem dia fixo. Rodar no dia 20
// pega a edicao do mes na grande maioria das vezes; se a pagina mudar ou a
// planilha vier truncada, o comando falha sem tocar na lista ja importada.
Schedule::command('cmed:importar')
    ->monthlyOn(20, '04:00')
    ->withoutOverlapping();
