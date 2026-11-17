<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * As escritas exigem o token dos scrapers; a leitura continua aberta.
 *
 * Nenhum caso aqui toca o banco: o middleware recusa antes do controller.
 */
class TokenColetaTest extends TestCase
{
    public function test_escrita_sem_token_e_recusada(): void
    {
        $this->withoutHeader('X-Api-Token')
            ->postJson('/api/coletas', [])
            ->assertUnauthorized();
    }

    public function test_escrita_com_token_errado_e_recusada(): void
    {
        $this->withHeader('X-Api-Token', 'outro-token')
            ->deleteJson('/api/links', [])
            ->assertUnauthorized();
    }

    public function test_sem_token_configurado_recusa_mesmo_com_cabecalho_vazio(): void
    {
        // Esquecer COLETA_TOKEN num servidor novo nao pode reabrir a API.
        config(['services.coleta.token' => '']);

        $this->withHeader('X-Api-Token', '')
            ->putJson('/api/produtos/laboratorio', [])
            ->assertUnauthorized();
    }

    public function test_escrita_com_token_certo_passa_pelo_middleware(): void
    {
        $status = $this->postJson('/api/precos', [])->status();

        $this->assertNotSame(401, $status);
    }
}
