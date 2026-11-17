<?php

use Illuminate\Support\Facades\Schedule;

// Quem dispara isto em producao e o cron do root no servidor, a cada minuto:
//   cd /home/chatbot/pharmaprice/api_pharmaprice && docker compose exec -T app php artisan schedule:run
// Os horarios abaixo sao em UTC (APP_TIMEZONE).

// Produtos que nenhuma coleta bem-sucedida encontra ha mais de 30 dias saem da
// consulta. O proprio comando se recusa a agir sobre farmacia cujo scraper nao
// concluiu coleta na ultima semana - scraper quebrado nao pode zerar catalogo.
//
// So com COLETA_DESATIVAR_AUTOMATICO=true. Na primeira conferencia em producao
// (17/11/2026) o --dry-run desativaria 59 mil produtos, 54 mil da Raia: numero
// grande demais para comecar sem alguem decidir.
Schedule::command('coletas:desativar-obsoletos')
    ->dailyAt('05:00')
    ->when(fn () => config('services.coleta.desativar_automatico'))
    ->withoutOverlapping();

// A Anvisa republica a lista da CMED todo mes, sem dia fixo. Rodar no dia 20
// pega a edicao do mes na grande maioria das vezes; se a pagina mudar ou a
// planilha vier truncada, o comando falha sem tocar na lista ja importada.
Schedule::command('cmed:importar')
    ->monthlyOn(20, '04:00')
    ->withoutOverlapping();
