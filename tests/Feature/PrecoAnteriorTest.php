<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A invariante que sustenta o painel.
 *
 * `precos.preco_anterior` guarda o valor que cada mudanca substituiu. Enquanto
 * ele estiver certo, o painel e uma agregacao direta sobre uma faixa de datas;
 * se sair de sincronia, o painel passa a reportar aumento onde houve queda —
 * que e pior do que reportar devagar.
 *
 * Aqui a conferencia e sempre contra o log, nunca contra o que a aplicacao
 * achava: quem escreve grava o que *acreditava* ser o anterior, e so `precos`
 * sabe qual era de fato.
 */
class PrecoAnteriorTest extends TestCase
{
    use RefreshDatabase;

    private int $farmaciaId;
    private int $produtoId;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

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

    /** Confere a coluna contra a linha imediatamente anterior do mesmo par. */
    private function assertAnteriorConsistente(): void
    {
        $errados = DB::selectOne('
            SELECT COUNT(*) AS total
            FROM (
                SELECT preco_anterior, data_anterior,
                       LAG(preco) OVER w AS esperado_preco,
                       LAG(data)  OVER w AS esperado_data
                FROM precos
                WINDOW w AS (PARTITION BY farmacia_id, produto_id ORDER BY preco_id)
            ) c
            WHERE NOT (c.preco_anterior <=> c.esperado_preco)
               OR NOT (c.data_anterior  <=> c.esperado_data)
        ')->total;

        $this->assertSame(0, (int) $errados, 'preco_anterior divergiu do log em precos');
    }

    private function postPreco(float $preco, string $data): void
    {
        $this->postJson('/api/precos', [
            'precos' => [[
                'farmacia_id' => $this->farmaciaId,
                'produto_id'  => $this->produtoId,
                'preco'       => $preco,
                'data'        => $data,
            ]],
        ])->assertOk();
    }

    /**
     * A primeira mudanca de um par nao substitui nada. Nulo aqui e informacao,
     * nao ausencia de informacao — e o que separa "primeiro preco conhecido" de
     * "preco que caiu para zero".
     */
    public function test_primeiro_preco_do_par_nao_tem_anterior(): void
    {
        $this->postPreco(12.90, '2026-08-01');

        $linha = DB::table('precos')->first();

        $this->assertNull($linha->preco_anterior);
        $this->assertNull($linha->data_anterior);
        $this->assertAnteriorConsistente();
    }

    public function test_post_precos_grava_o_valor_que_substituiu(): void
    {
        $this->postPreco(12.90, '2026-08-01');
        $this->postPreco(9.50, '2026-08-10');

        $ultima = DB::table('precos')->orderByDesc('preco_id')->first();

        $this->assertSame('12.90', $ultima->preco_anterior);
        $this->assertSame('2026-08-01', $ultima->data_anterior);
        $this->assertAnteriorConsistente();
    }

    public function test_heartbeat_de_coleta_grava_o_valor_que_substituiu(): void
    {
        DB::table('informacoes_produtos')->insert([
            'farmacia_id' => $this->farmaciaId,
            'produto_id'  => $this->produtoId,
            'sku'         => 'SKU-1',
            'ativo'       => 1,
        ]);

        $coletar = fn (float $preco, string $data) => $this->postJson('/api/coletas', [
            'farmacia_id' => $this->farmaciaId,
            'data'        => $data,
            'itens'       => [[
                'produto_id' => $this->produtoId,
                'preco'      => $preco,
                'status'     => 'ok',
            ]],
        ]);

        $coletar(7.35, '2026-08-01')->assertOk()->assertJsonPath('precos_alterados', 1);
        $coletar(8.10, '2026-08-05')->assertOk()->assertJsonPath('precos_alterados', 1);

        $ultima = DB::table('precos')->orderByDesc('preco_id')->first();

        $this->assertSame('7.35', $ultima->preco_anterior);
        $this->assertSame('2026-08-01', $ultima->data_anterior);
        $this->assertAnteriorConsistente();
    }

    /**
     * Duas mudancas na mesma data eram exatamente o caso que a consulta antiga
     * errava: ela ligava as linhas por `data`, entao as duas casavam com a
     * mesma anterior e a mudanca era contada duas vezes. A ligacao por
     * `preco_id` nao empata.
     */
    public function test_duas_mudancas_na_mesma_data_encadeiam_sem_ambiguidade(): void
    {
        $this->postPreco(10.00, '2026-08-01');
        $this->postPreco(11.00, '2026-08-01');
        $this->postPreco(12.00, '2026-08-01');

        $linhas = DB::table('precos')->orderBy('preco_id')->get();

        $this->assertNull($linhas[0]->preco_anterior);
        $this->assertSame('10.00', $linhas[1]->preco_anterior);
        $this->assertSame('11.00', $linhas[2]->preco_anterior);
        $this->assertAnteriorConsistente();
    }

    /** Pares diferentes nao podem enxergar o anterior um do outro. */
    public function test_series_de_farmacias_diferentes_nao_se_misturam(): void
    {
        $outraFarmacia = DB::table('farmacias')->insertGetId([
            'nome_farmacia' => 'Outrafarma',
            'url_base'      => 'https://outra.test',
        ], 'farmacia_id');

        $this->postPreco(10.00, '2026-08-01');

        $this->postJson('/api/precos', [
            'precos' => [[
                'farmacia_id' => $outraFarmacia,
                'produto_id'  => $this->produtoId,
                'preco'       => 50.00,
                'data'        => '2026-08-02',
            ]],
        ])->assertOk();

        $this->postPreco(11.00, '2026-08-03');

        $ultima = DB::table('precos')->orderByDesc('preco_id')->first();

        // 10.00 da propria farmacia, e nao 50.00 da outra.
        $this->assertSame('10.00', $ultima->preco_anterior);
        $this->assertAnteriorConsistente();
    }

    public function test_reconciliacao_detecta_e_conserta_anterior_errado(): void
    {
        $this->postPreco(10.00, '2026-08-01');
        $this->postPreco(11.00, '2026-08-05');

        // Simula escrita fora da aplicacao — carga por SQL, restauracao parcial.
        DB::table('precos')
            ->orderByDesc('preco_id')
            ->limit(1)
            ->update(['preco_anterior' => 999.99]);

        $this->artisan('precos:reconciliar --check')->assertExitCode(1);
        $this->artisan('precos:reconciliar')->assertExitCode(0);

        $this->assertSame(
            '10.00',
            DB::table('precos')->orderByDesc('preco_id')->value('preco_anterior')
        );
        $this->assertAnteriorConsistente();
    }

    /**
     * O painel conta o que aconteceu na janela, e a contagem tem que bater com
     * o que foi de fato inserido — inclusive a primeira mudanca de cada
     * produto dentro dela, que a versao com LAG descartava.
     */
    public function test_painel_conta_aumentos_e_reducoes_da_janela(): void
    {
        $hoje  = now()->toDateString();
        $ontem = now()->subDay()->toDateString();

        $this->postPreco(10.00, $ontem);   // primeiro preço: não é mudança
        $this->postPreco(12.00, $hoje);    // aumento
        $this->postPreco(11.00, $hoje);    // redução

        $resposta = $this->getJson('/api/dashboard/statistics?days=7')->assertOk();

        $resposta->assertJsonPath('data.price_increases', 1);
        $resposta->assertJsonPath('data.price_decreases', 1);
        $resposta->assertJsonPath('data.updated_products', 1);
    }

    /** A série diária cobre todo o período pedido, com zero onde nada mudou. */
    public function test_tendencias_cobrem_todos_os_dias_do_periodo(): void
    {
        $this->postPreco(10.00, now()->toDateString());

        $resposta = $this->getJson('/api/dashboard/trends?days=7')->assertOk();

        $resposta->assertJsonCount(7, 'data');
        $resposta->assertJsonPath('data.6.date', now()->toDateString());
    }

    /** Limpar o cache tem que mudar a resposta, e não só devolver 200. */
    public function test_limpar_cache_faz_o_painel_recalcular(): void
    {
        $this->postPreco(10.00, now()->subDay()->toDateString());
        $this->postPreco(12.00, now()->toDateString());

        $this->getJson('/api/dashboard/statistics?days=7')
            ->assertJsonPath('data.price_increases', 1);

        $this->postPreco(15.00, now()->toDateString());

        // Sem limpar, a resposta ainda é a da geração anterior.
        $this->getJson('/api/dashboard/statistics?days=7')
            ->assertJsonPath('data.price_increases', 1);

        $this->postJson('/api/dashboard/cache/clear')->assertOk();

        $this->getJson('/api/dashboard/statistics?days=7')
            ->assertJsonPath('data.price_increases', 2);
    }
}
