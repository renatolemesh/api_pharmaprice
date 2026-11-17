<?php

namespace Tests\Feature;

use App\Support\BuscaSimilar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Busca por similaridade: o que o LIKE perde ("prot sol" para "Protetor
 * Solar") tem que aparecer, sem tirar do LIKE o que ele ja achava.
 *
 * O que esta em teste e o comportamento do pg_trgm e da funcao
 * normalizar_busca, entao roda no Postgres de verdade (ver phpunit.xml).
 */
class BuscaSimilarTest extends TestCase
{
    use RefreshDatabase;

    private int $farmacia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farmacia = DB::table('farmacias')->insertGetId([
            'nome_farmacia' => 'Testefarma',
            'url_base'      => 'https://exemplo.test',
        ], 'farmacia_id');

        $this->produto('PROT SOLAR COPPERTONE SPORT FPS 50 120ML', '7891106911085', 39.90);
        $this->produto('Protetor Solar Nivea Sun Protect &amp; Hidrata FPS50 200ml', '4005900741165', 59.90);
        $this->produto('Protetor Solar Facial Episol FPS 30 40g', '7896015590123', 49.90);
        $this->produto('Dipirona Sódica 500mg 10 comprimidos', '7893333333333', 4.00);
        $this->produto('Shampoo Bed Head Gimme Grip 400ml', '7894444444444', 89.00);
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

    public function test_descricao_editada_vale_na_hora(): void
    {
        // texto_busca e coluna gerada: nao existe indice para ficar atrasado.
        DB::table('produtos')->where('EAN', '7893333333333')->update(['descricao' => 'Dipirona Monoidratada 1g']);

        $this->assertSame(
            ['Dipirona Monoidratada 1g'],
            $this->descricoes('/api/precos?modo=similar&descricao=' . urlencode('dipir monoidr'))
        );
    }

    public function test_like_ignora_maiuscula_acento_e_pontuacao(): void
    {
        // O MySQL fazia as duas primeiras pela collation; no Postgres e a
        // normalizacao que garante.
        $this->assertSame(['Dipirona Sódica 500mg 10 comprimidos'], $this->descricoes('/api/precos?descricao=DIPIRONA%20SODICA'));
        $this->assertSame(['Dipirona Sódica 500mg 10 comprimidos'], $this->descricoes('/api/precos?descricao=' . urlencode('500 mg')));
        $this->assertSame(
            ['Protetor Solar Nivea Sun Protect &amp; Hidrata FPS50 200ml'],
            $this->descricoes('/api/precos?descricao=' . urlencode('protect & hidrata'))
        );
    }

    public function test_texto_sem_palavra_que_filtre_nao_acha_nada(): void
    {
        $this->assertSame([], $this->descricoes('/api/precos?modo=similar&descricao=de'));
    }

    public function test_autocomplete_similar(): void
    {
        $sugestoes = collect($this->getJson('/api/descricoes?modo=similar&descricao=' . urlencode('prot sol'))
            ->assertOk()->json())->pluck('descricao');

        $this->assertContains('PROT SOLAR COPPERTONE SPORT FPS 50 120ML', $sugestoes);
        $this->assertNotContains('Dipirona Sódica 500mg 10 comprimidos', $sugestoes);
    }

    public function test_exportacao_traz_o_mesmo_que_a_tela(): void
    {
        $resposta = $this->get('/api/report/export?formato=csv&priceType=current&modo=similar&descricao=' . urlencode('prot sol fps 50'))
            ->assertOk();

        $csv = $resposta->streamedContent();
        $this->assertStringContainsString('PROT SOLAR COPPERTONE', $csv);
        $this->assertStringContainsString('Nivea Sun', $csv);
        $this->assertStringNotContainsString('Episol', $csv);
    }
}
