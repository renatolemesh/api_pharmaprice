<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Token de coleta valido em todo teste.
     *
     * As rotas de escrita exigem X-Api-Token (middleware token.coleta). Sem
     * isto cada teste que grava dados receberia 401 antes de chegar ao
     * controller. O comportamento do proprio middleware fica em
     * TokenColetaTest, que tira o cabecalho ou troca o token.
     */
    protected const TOKEN_COLETA = 'token-de-teste';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.coleta.token' => self::TOKEN_COLETA]);
        $this->withHeader('X-Api-Token', self::TOKEN_COLETA);
    }
}
