<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Toda rota da API respondendo sem erro, com dados de verdade no banco.
 *
 * Nasceu na troca do MySQL pelo Postgres: varias rotas nao tinham teste, e SQL
 * escrito para um banco so quebra no outro quando executa. Aqui nao se confere
 * o conteudo de cada resposta - isso e dos testes de cada assunto -, so que a
 * consulta roda.
 */
class RotasTest extends TestCase
{
    use RefreshDatabase;

    private int $farmacia;
    private int $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farmacia = DB::table('farmacias')->insertGetId([
            'nome_farmacia' => 'Testefarma',
            'url_base'      => 'https://exemplo.test',
        ], 'farmacia_id');

        $this->produto = DB::table('produtos')->insertGetId([
            'descricao'   => 'Dipirona Sódica 500mg 10 comprimidos',
            'EAN'         => '7891111111111',
            'laboratorio' => 'EMS',
        ], 'produto_id');

        DB::table('informacoes_produtos')->insert([
            'farmacia_id'      => $this->farmacia,
            'produto_id'       => $this->produto,
            'link'             => 'https://exemplo.test/dipirona',
            'sku'              => '123',
            'ativo'            => true,
            'ultima_coleta_em' => now()->subDays(2),
        ]);

        DB::table('links')->insert(['farmacia_id' => $this->farmacia, 'link' => 'https://exemplo.test/novo']);

        // Duas mudancas dentro da semana, para o painel ter o que contar.
        foreach ([[10.00, 3], [12.50, 1]] as [$preco, $dias]) {
            $this->postJson('/api/precos', ['precos' => [[
                'farmacia_id' => $this->farmacia,
                'produto_id'  => $this->produto,
                'preco'       => $preco,
                'data'        => now()->subDays($dias)->toDateString(),
            ]]])->assertOk();
        }
    }

    public function test_leituras(): void
    {
        $f = $this->farmacia;
        $p = $this->produto;

        $rotas = [
            '/api/farmacias',
            '/api/precos',
            "/api/precos?descricao=dipirona&farmacia={$f}",
            '/api/precos?ean=7891111111111',
            '/api/precos?descricao=dipirona&modo=similar&incluir_grupos=1',
            '/api/grupos',
            '/api/precos/historico?descricao=dipirona',
            '/api/precos/historico?ean=7891111111111',
            '/api/precos/variacoes',
            '/api/produtos?ean=7891111111111',
            '/api/produtos/all',
            "/api/informacoes_produtos?farmacia_id={$f}&produto_id={$p}",
            "/api/informacoes_produtos/all?farmacia_id={$f}",
            "/api/preco_atual?farmacia_id={$f}&produto_id={$p}",
            "/api/links?farmacia_id={$f}",
            '/api/descricoes?descricao=dipi',
            '/api/coletas/saude',
            '/api/report/export?formato=csv&priceType=current&descricao=dipirona',
            '/api/report/export?formato=csv&priceType=historical&descricao=dipirona&data-inicio=2020-01-01',
            '/api/report/export?formato=excel&priceType=variation',
            '/api/dashboard/statistics?days=7',
            '/api/dashboard/trends?days=7',
            '/api/dashboard/top-changes?type=increase',
            '/api/dashboard/top-changes?type=decrease',
            '/api/dashboard/top-changes',
            '/api/dashboard/pharmacy-stats',
            '/api/dashboard/summary',
        ];

        foreach ($rotas as $rota) {
            $resposta = $this->get($rota);
            $this->assertSame(200, $resposta->getStatusCode(), "GET {$rota} respondeu {$resposta->getStatusCode()}");

            // Exportacao responde em streaming: a consulta so roda quando o
            // conteudo e lido, e um erro nela viria depois do 200.
            if ($resposta->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
                $this->assertNotSame('', $resposta->streamedContent(), "GET {$rota} veio vazio");
            }
        }
    }

    public function test_ativo_sai_como_numero(): void
    {
        // O front marca inativo com `item.ativo === 0`; o boolean do Postgres
        // chegaria como false e o selo sumiria sem erro nenhum.
        DB::table('informacoes_produtos')->update(['ativo' => false]);

        $this->getJson('/api/precos?incluir_inativos=1')
            ->assertOk()
            ->assertJsonPath('data.0.ativo', 0);
    }

    public function test_coleta_ponta_a_ponta(): void
    {
        $f = $this->farmacia;

        $execucao = $this->postJson('/api/coletas/execucoes', ['farmacia_id' => $f, 'script' => 'teste'])
            ->assertSuccessful()->json('execucao_id');

        $this->postJson('/api/coletas', [
            'farmacia_id' => $f,
            'execucao_id' => $execucao,
            'itens'       => [
                ['produto_id' => $this->produto, 'preco' => 11.90, 'status' => 'ok'],
                ['sku' => '123', 'preco' => 11.90, 'status' => 'ok'],
                ['sku' => 'nao-existe', 'status' => 'sem_estoque'],
            ],
        ])->assertOk();

        $this->patchJson("/api/coletas/execucoes/{$execucao}", ['status' => 'concluida'])->assertSuccessful();

        $this->assertSame(11.90, (float) DB::table('precos_atuais')->where('produto_id', $this->produto)->value('preco'));
    }

    public function test_cadastros(): void
    {
        $f = $this->farmacia;

        $this->postJson('/api/produtos/lote', [
            'farmacia_id' => $f,
            'itens' => [
                ['ean' => '7892222222222', 'descricao' => 'Paracetamol 750mg', 'laboratorio' => 'Medley', 'sku' => '456', 'link' => 'https://exemplo.test/para'],
                // EAN que ja existe: reaproveita o produto.
                ['ean' => '7891111111111', 'descricao' => 'Dipirona', 'sku' => '123', 'link' => 'https://exemplo.test/dipirona'],
            ],
        ])->assertOk();
        $this->assertSame(2, DB::table('produtos')->count());

        $this->putJson('/api/produtos/laboratorio', ['itens' => [
            ['ean' => '7892222222222', 'laboratorio' => 'Medley Genericos', 'anterior' => 'Medley'],
        ]])->assertOk();

        $novo = $this->postJson('/api/produtos', ['descricao' => 'Ibuprofeno 400mg', 'ean' => '7893333333333'])
            ->assertCreated()->json('produto_id');
        $this->assertSame('7893333333333', DB::table('produtos')->where('produto_id', $novo)->value('EAN'));
        // EAN repetido e recusado pela validacao, nao pelo banco.
        $this->postJson('/api/produtos', ['descricao' => 'Outro', 'ean' => '7893333333333'])->assertStatus(422);

        $this->postJson('/api/informacoes_produtos', [
            'farmacia_id' => $f, 'produto_id' => $novo, 'link' => 'https://exemplo.test/ibu', 'sku' => '789',
        ])->assertSuccessful();
        $this->putJson('/api/informacoes_produtos', [
            'farmacia_id' => $f, 'produto_id' => $novo, 'link' => 'https://exemplo.test/ibu-2', 'sku' => '789',
        ])->assertSuccessful();

        $this->postJson('/api/links', ['farmacia_id' => $f, 'link' => 'https://exemplo.test/outro'])->assertSuccessful();
        $this->deleteJson('/api/links', ['farmacia_id' => $f, 'link' => 'https://exemplo.test/outro'])->assertSuccessful();

        $this->postJson('/api/dashboard/cache/clear')->assertSuccessful();
    }

    public function test_comandos(): void
    {
        $this->assertSame(0, Artisan::call('precos:reconciliar', ['--check' => true]));
        $this->assertSame(0, Artisan::call('precos:reconciliar'));
        $this->assertSame(0, Artisan::call('coletas:desativar-obsoletos', ['--dry-run' => true]));
    }
}
