<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Token que os scrapers mandam no cabecalho X-Api-Token para as rotas de
    // escrita (ver App\Http\Middleware\ExigeTokenColeta).
    'coleta' => [
        'token' => env('COLETA_TOKEN'),
        // Liga a desativacao diaria de produtos sem coleta ha 30 dias
        // (coletas:desativar-obsoletos no agendador). Desligada ate alguem
        // olhar o --dry-run e concordar com o numero.
        'desativar_automatico' => (bool) env('COLETA_DESATIVAR_AUTOMATICO', false),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
