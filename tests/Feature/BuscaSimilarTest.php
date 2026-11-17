<?php

namespace Tests\Feature;

use App\Support\BuscaSimilar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Busca por similaridade: o que o LIKE perde ("prot sol" para "Protetor
 * Solar") tem que aparecer, e o indice fora do ar nao pode derrubar a busca.
 *
 * Usa o Postgres de verdade (banco busca_test, ver phpunit.xml): o que esta em
 * teste e justamente o comportamento do pg_trgm.
 */
class BuscaSimilarTest extends TestCase
{
    use RefreshDatabase;

    private int $farmacia;

    protected function setUp(): void
    {
        parent::setUp();

        BuscaSimilar::garantirEsquema();
        DB::connection(BuscaSimilar::CONEXAO)->statement('TRUNCATE produtos_busca');

        $this->farmacia = DB::table('farmacias')->insertGetId([
            'nome_farmacia' => 'Testefarma',
            'url_base'      => 'https://exemplo.test',
        ], 'farmacia_id');

        $this->produto('PROT SOLAR COPPERTONE SPORT FPS 50 120ML', '7891106911085', 39.90);
        $this->produto('Protetor Solar Nivea Sun Protect &amp; Hidrata FPS50 200ml', '4005900741165', 59.90);
        $this->produto('Protetor Solar Facial Episol FPS 30 40g', '7896015590123', 49.90);
        $this->produto('Dipirona Sódica 500mg 10 comprimidos', '7893333333333', 4.00);
        $this->produto('Shampoo Bed Head Gimme Grip 400ml', '7894444444444', 89.00);

        BuscaSimilar::sincronizar();
    }

    private function produto(string $descricao, string $ean, float $preco): int
    {
        $id = DB::table('produtos')->insertGetId(['descricao' => $descricao, 'EAN' => $ean], 'produto_id');
        DB::table('precos_atuais')->insert([
            'farmacia_id' => $this->farmacia,
            'produto_id'  => $id,
            'preco'       => $preco,
            'data'        => '2026-11-01',
        ]);

        return $id;
    }

    private function descricoes(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('descricao')->sort()->values()->all();
    }

    public function test_normalizacao(): void
    {
        $this->assertSame('protetor solar 50 g', BuscaSimilar::normalizar('Protetor  Solar 50G'));
        $this->assertSame('fps 50 200 ml', BuscaSimilar::normalizar('FPS50 200ml'));
        $this->assertSame('prot sol', BuscaSimilar::normalizar('Prot. Sol'));
        // Escapado duas vezes, como chega de alguns scrapers.
        $this->assertSame('l oreal', BuscaSimilar::normalizar('L&amp;#039;Oréal'));
    }

    public function test_palavras_que_nao_filtram_saem(): void
    {
        $this->assertSame(['gripe'], BuscaSimilar::palavras('remédio para gripe'));
        $this->assertSame(['protetor', 'solar'], BuscaSimilar::palavras('protetor solar a'));
    }

    public function test_abreviado_acha_o_nome_completo(): void
    {
        $this->assertSame(
            [
                'PROT SOLAR COPPERTONE SPORT FPS 50 120ML',
                'Protetor Solar Nivea Sun Protect &amp; Hidrata FPS50 200ml',
            ],
            $this->descricoes('/api/precos?modo=similar&descricao=' . urlencode('prot sol fps 50'))
        );
    }

    public function test_nome_completo_acha_o_abreviado(): void
    {
        // O LIKE nao acha "PROT SOLAR" buscando "protetor solar".
        $this->assertCount(2, $this->descricoes('/api/precos?descricao=' . urlencode('protetor solar')));

        $this->assertCount(3, $this->descricoes('/api/precos?modo=similar&descricao=' . urlencode('protetor solar')));
    }

    public function test_cada_palavra_precisa_casar(): void
    {
        // "facial" so existe no Episol; os outros protetores ficam de fora.
        $this->assertSame(
            ['Protetor Solar Facial Episol FPS 30 40g'],
            $this->descricoes('/api/precos?modo=similar&descricao=' . urlencode('protetor facial'))
        );
    }

    public function test_sem_o_parametro_continua_like(): void
    {
        $this->assertSame([], $this->descricoes('/api/precos?descricao=' . urlencode('prot sol fps 50')));
    }

    public function test_produto_novo_entra_no_indice_ao_ser_cadastrado(): void
    {
        $this->postJson('/api/produtos', [
            'descricao' => 'Prot Solar Infantil Sundown Kids FPS 60',
            'ean'       => '7891010101010',
        ])->assertCreated();

        $this->assertContains(
            'Prot Solar Infantil Sundown Kids FPS 60',
            BuscaSimilar::descricoes('protetor infantil', 10)
        );
    }

    public function test_sincronizacao_incremental_pega_so_o_que_falta(): void
    {
        $this->assertSame(0, BuscaSimilar::sincronizar());

        $this->produto('Fralda Pampers Confort Sec G 34 unidades', '7500435000001', 69.90);

        $this->assertSame(1, BuscaSimilar::sincronizar());
        $this->assertSame(6, BuscaSimilar::sincronizar(completo: true));
    }

    public function test_autocomplete_similar(): void
    {
        $sugestoes = collect($this->getJson('/api/descricoes?modo=similar&descricao=' . urlencode('prot sol'))
            ->assertOk()->json())->pluck('descricao');

        $this->assertContains('PROT SOLAR COPPERTONE SPORT FPS 50 120ML', $sugestoes);
        $this->assertNotContains('Dipirona Sódica 500mg 10 comprimidos', $sugestoes);
    }

    public function test_indice_fora_do_ar_cai_no_like(): void
    {
        Config::set('database.connections.busca.host', 'host-que-nao-existe.invalid');
        DB::purge(BuscaSimilar::CONEXAO);

        $this->assertNull(BuscaSimilar::produtoIds('dipirona'));

        // A busca responde, pelo LIKE, em vez de dar erro 500.
        $this->assertSame(
            ['Dipirona Sódica 500mg 10 comprimidos'],
            $this->descricoes('/api/precos?modo=similar&descricao=dipirona')
        );
    }

    public function test_cadastro_nao_falha_com_indice_fora_do_ar(): void
    {
        Config::set('database.connections.busca.host', 'host-que-nao-existe.invalid');
        DB::purge(BuscaSimilar::CONEXAO);

        $this->postJson('/api/produtos', [
            'descricao' => 'Produto cadastrado com indice fora',
            'ean'       => '7891010101011',
        ])->assertCreated();
    }
}
