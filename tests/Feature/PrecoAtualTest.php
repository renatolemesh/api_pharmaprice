<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A invariante que sustenta a busca rapida.
 *
 * `precos_atuais` e uma projecao de `precos`. Enquanto ela estiver certa, a
 * busca responde em milissegundos; se ela sair de sincronia, a busca passa a
 * responder preco que nao existe — que e pior do que responder devagar. Estes
 * testes exercitam os dois caminhos de escrita e conferem a invariante depois
 * de cada um.
 */
class PrecoAtualTest extends TestCase
{
    use RefreshDatabase;

    private int $farmaciaId;
    private int $produtoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farmaciaId = DB::table('farmacias')->insertGetId([
            'nome_farmacia' => 'Testefarma',
            'url_base'      => 'https://exemplo.test',
        ], 'farmacia_id');

        $this->produtoId = DB::table('produtos')->insertGetId([
            'descricao'   => 'Dipirona Sódica 500mg 20 comprimidos',
            'EAN'         => '7891234567890',
            'laboratorio' => 'Teste',
        ], 'produto_id');
    }

    /** Compara a projecao com o que a definicao dela manda ser. */
    private function assertProjecaoConsistente(): void
    {
        $divergentes = DB::selectOne('
            SELECT COUNT(*) AS total
            FROM (
                SELECT p.farmacia_id, p.produto_id, p.preco, p.preco_id
                FROM precos p
                INNER JOIN (
                    SELECT farmacia_id, produto_id, MAX(preco_id) AS max_id
                    FROM precos
                    WHERE farmacia_id IS NOT NULL AND produto_id IS NOT NULL
                    GROUP BY farmacia_id, produto_id
                ) ult ON p.preco_id = ult.max_id
                WHERE p.preco IS NOT NULL
            ) esperado
            LEFT JOIN precos_atuais a
              ON a.farmacia_id = esperado.farmacia_id
             AND a.produto_id  = esperado.produto_id
            WHERE a.produto_id IS NULL
               OR a.preco_id  <> esperado.preco_id
               OR a.preco     <> esperado.preco
        ')->total;

        $this->assertSame(0, (int) $divergentes, 'precos_atuais divergiu de precos');
    }

    public function test_post_precos_projeta_o_preco_atual(): void
    {
        $this->postJson('/api/precos', [
            'precos' => [[
                'farmacia_id' => $this->farmaciaId,
                'produto_id'  => $this->produtoId,
                'preco'       => 12.90,
                'data'        => '2026-08-01',
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('precos_atuais', [
            'farmacia_id' => $this->farmaciaId,
            'produto_id'  => $this->produtoId,
            'preco'       => 12.90,
        ]);

        $this->assertProjecaoConsistente();
    }

    public function test_preco_novo_substitui_o_anterior_na_projecao(): void
    {
        foreach ([[12.90, '2026-08-01'], [9.50, '2026-08-10']] as [$preco, $data]) {
            $this->postJson('/api/precos', [
                'precos' => [[
                    'farmacia_id' => $this->farmaciaId,
                    'produto_id'  => $this->produtoId,
                    'preco'       => $preco,
                    'data'        => $data,
                ]],
            ])->assertOk();
        }

        // Duas linhas no log, uma na projecao: e essa a diferenca entre as duas
        // tabelas.
        $this->assertSame(2, DB::table('precos')->count());
        $this->assertSame(1, DB::table('precos_atuais')->count());
        $this->assertSame('9.50', DB::table('precos_atuais')->value('preco'));

        $this->assertProjecaoConsistente();
    }

    public function test_heartbeat_de_coleta_projeta_o_preco_atual(): void
    {
        DB::table('informacoes_produtos')->insert([
            'farmacia_id' => $this->farmaciaId,
            'produto_id'  => $this->produtoId,
            'sku'         => 'SKU-1',
            'link'        => '/dipirona-500mg',
            'ativo'       => 1,
        ]);

        $this->postJson('/api/coletas', [
            'farmacia_id' => $this->farmaciaId,
            'data'        => '2026-08-15',
            'itens'       => [[
                'produto_id' => $this->produtoId,
                'preco'      => 7.35,
                'status'     => 'ok',
            ]],
        ])->assertOk()->assertJsonPath('precos_alterados', 1);

        $this->assertSame('7.35', DB::table('precos_atuais')->value('preco'));
        $this->assertProjecaoConsistente();
    }

    /**
     * Preco estavel nao gera linha em `precos` — e tambem nao pode fazer a
     * projecao apontar pra lugar nenhum.
     */
    public function test_preco_estavel_nao_duplica_nem_perde_a_projecao(): void
    {
        DB::table('informacoes_produtos')->insert([
            'farmacia_id' => $this->farmaciaId,
            'produto_id'  => $this->produtoId,
            'sku'         => 'SKU-1',
            'ativo'       => 1,
        ]);

        $enviar = fn () => $this->postJson('/api/coletas', [
            'farmacia_id' => $this->farmaciaId,
            'itens'       => [[
                'produto_id' => $this->produtoId,
                'preco'      => 7.35,
                'status'     => 'ok',
            ]],
        ]);

        $enviar()->assertOk()->assertJsonPath('precos_alterados', 1);
        $enviar()->assertOk()->assertJsonPath('precos_alterados', 0);

        $this->assertSame(1, DB::table('precos')->count());
        $this->assertSame(1, DB::table('precos_atuais')->count());
        $this->assertProjecaoConsistente();
    }

    public function test_busca_devolve_o_preco_projetado(): void
    {
        $this->postJson('/api/precos', [
            'precos' => [[
                'farmacia_id' => $this->farmaciaId,
                'produto_id'  => $this->produtoId,
                'preco'       => 12.90,
                'data'        => '2026-08-01',
            ]],
        ])->assertOk();

        $this->getJson('/api/precos?ean=7891234567890')
            ->assertOk()
            ->assertJsonPath('data.0.preco', '12.90')
            ->assertJsonPath('data.0.nome_farmacia', 'Testefarma');
    }

    public function test_comando_de_reconciliacao_conserta_divergencia(): void
    {
        $this->postJson('/api/precos', [
            'precos' => [[
                'farmacia_id' => $this->farmaciaId,
                'produto_id'  => $this->produtoId,
                'preco'       => 12.90,
                'data'        => '2026-08-01',
            ]],
        ])->assertOk();

        // Simula escrita fora da aplicacao — carga por SQL, restauracao parcial.
        DB::table('precos_atuais')->update(['preco' => 999.99]);

        $this->artisan('precos:reconciliar --check')->assertExitCode(1);
        $this->artisan('precos:reconciliar')->assertExitCode(0);

        $this->assertSame('12.90', DB::table('precos_atuais')->value('preco'));
        $this->assertProjecaoConsistente();
    }
}
