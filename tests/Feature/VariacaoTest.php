<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Relatorio de aumentos e reducoes.
 *
 * Cada linha e uma mudanca de preco com a variacao sobre o preco que ela
 * substituiu. O que se cobra aqui e o que faria o relatorio mentir: a conta da
 * variacao, o sentido da ordenacao, o corte de provavel erro de coleta (que nao
 * pode sumir sem aparecer na contagem) e a tela batendo com o arquivo baixado.
 */
class VariacaoTest extends TestCase
{
    use RefreshDatabase;

    private int $farmaciaA;
    private int $farmaciaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farmaciaA = DB::table('farmacias')->insertGetId([
            'nome_farmacia' => 'Testefarma',
            'url_base'      => 'https://exemplo.test',
        ], 'farmacia_id');

        $this->farmaciaB = DB::table('farmacias')->insertGetId([
            'nome_farmacia' => 'Outrafarma',
            'url_base'      => 'https://outra.test',
        ], 'farmacia_id');
    }

    private function produto(string $descricao = 'Dipirona Sódica 500mg 20 comprimidos', ?string $ean = null): int
    {
        static $sequencia = 0;
        $sequencia++;

        return DB::table('produtos')->insertGetId([
            'descricao'   => $descricao,
            'EAN'         => $ean ?? (string) (7891000000000 + $sequencia),
            'laboratorio' => 'Teste',
        ], 'produto_id');
    }

    /** Uma mudanca de preco, gravada como o escritor grava: com o anterior na linha. */
    private function mudanca(int $produto, ?float $anterior, float $preco, int $diasAtras = 1, ?int $farmacia = null): int
    {
        $data = Carbon::today()->subDays($diasAtras);

        return DB::table('precos')->insertGetId([
            'farmacia_id'    => $farmacia ?? $this->farmaciaA,
            'produto_id'     => $produto,
            'preco'          => $preco,
            'data'           => $data->toDateString(),
            'preco_anterior' => $anterior,
            'data_anterior'  => $anterior === null ? null : $data->copy()->subDays(7)->toDateString(),
        ], 'preco_id');
    }

    private function variacoes(string $query = ''): TestResponse
    {
        return $this->getJson('/api/precos/variacoes' . ($query ? "?{$query}" : ''));
    }

    /** @return array<int, float> */
    private function percentuais(TestResponse $resposta): array
    {
        return array_map(fn ($linha) => (float) $linha['variacao'], $resposta->json('data'));
    }

    public function test_variacao_e_sobre_o_preco_anterior(): void
    {
        $subiu = $this->produto('Dipirona');
        $caiu = $this->produto('Dorflex');

        $this->mudanca($subiu, 10.00, 12.50);
        $this->mudanca($caiu, 20.00, 15.00);

        $resposta = $this->variacoes()->assertOk();

        $resposta->assertJsonPath('total', 2)
            ->assertJsonPath('resumo.aumentos', 1)
            ->assertJsonPath('resumo.reducoes', 1);

        $linhas = collect($resposta->json('data'))->keyBy('descricao');
        $this->assertEqualsWithDelta(25.0, (float) $linhas['Dipirona']['variacao'], 0.001);
        $this->assertEqualsWithDelta(-25.0, (float) $linhas['Dorflex']['variacao'], 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $linhas['Dipirona']['preco_anterior'], 0.001);
        $this->assertSame('Testefarma', $linhas['Dipirona']['nome_farmacia']);
    }

    /** Primeiro preco conhecido do par nao substitui nada: nao e aumento nem reducao. */
    public function test_primeiro_preco_do_par_nao_entra(): void
    {
        $this->mudanca($this->produto(), null, 12.50);

        $this->variacoes()->assertOk()->assertJsonPath('total', 0)->assertJsonPath('data', []);
    }

    /**
     * De ponta a ponta, pelo mesmo caminho que a coleta usa: o relatorio le o
     * anterior que o escritor gravou, entao os dois precisam concordar.
     */
    public function test_le_o_anterior_gravado_pelo_post_de_precos(): void
    {
        $produto = $this->produto();

        foreach ([[10.00, 5], [12.00, 2]] as [$preco, $diasAtras]) {
            $this->postJson('/api/precos', ['precos' => [[
                'farmacia_id' => $this->farmaciaA,
                'produto_id'  => $produto,
                'preco'       => $preco,
                'data'        => Carbon::today()->subDays($diasAtras)->toDateString(),
            ]]])->assertOk();
        }

        $resposta = $this->variacoes()->assertOk()->assertJsonPath('total', 1);
        $this->assertEqualsWithDelta(20.0, $this->percentuais($resposta)[0], 0.001);
    }

    /** Maior movimento primeiro, no sentido pedido. */
    public function test_ordena_pelo_tamanho_do_movimento_no_sentido_pedido(): void
    {
        $this->mudanca($this->produto(), 10.00, 11.00); // +10%
        $this->mudanca($this->produto(), 10.00, 7.00);  // -30%
        $this->mudanca($this->produto(), 10.00, 12.00); // +20%

        $this->assertEquals([-30.0, 20.0, 10.0], $this->percentuais($this->variacoes()));
        $this->assertEquals([20.0, 10.0], $this->percentuais($this->variacoes('tipo=aumento')));
        $this->assertEquals([-30.0], $this->percentuais($this->variacoes('tipo=reducao')));
    }

    public function test_ordem_por_data_traz_a_mais_recente_primeiro(): void
    {
        $this->mudanca($this->produto('Antiga'), 10.00, 19.00, 10);
        $this->mudanca($this->produto('Recente'), 10.00, 11.00, 1);

        $descricoes = array_column($this->variacoes('ordem=data')->json('data'), 'descricao');
        $this->assertSame(['Recente', 'Antiga'], $descricoes);
    }

    /**
     * O corte de erro de coleta: fora da lista por padrao, mas contado — e
     * de volta a um parametro de distancia. Os limites em si (+500% e -80%)
     * entram, como no painel.
     */
    public function test_provavel_erro_de_coleta_sai_da_lista_mas_entra_na_contagem(): void
    {
        $this->mudanca($this->produto('FABRAZYME'), 41.99, 23070.60); // +54.843%
        $this->mudanca($this->produto('Troca de caixa'), 10.00, 1.00); // -90%
        $this->mudanca($this->produto('Normal'), 10.00, 11.00);        // +10%
        $this->mudanca($this->produto('No teto'), 10.00, 60.00);       // +500%
        $this->mudanca($this->produto('No piso'), 10.00, 2.00);        // -80%

        $padrao = $this->variacoes()->assertOk();
        $this->assertEqualsCanonicalizing(
            ['Normal', 'No teto', 'No piso'],
            array_column($padrao->json('data'), 'descricao')
        );
        $padrao->assertJsonPath('total', 3)->assertJsonPath('resumo.suspeitas', 2);

        // Pedindo so aumentos, a queda absurda nao esta "oculta": nao foi pedida.
        $this->variacoes('tipo=aumento')->assertJsonPath('resumo.suspeitas', 1);

        $tudo = $this->variacoes('incluir_suspeitas=1')->assertOk();
        $tudo->assertJsonPath('total', 5);
        $this->assertSame('FABRAZYME', $tudo->json('data.0.descricao'));
    }

    public function test_filtra_por_farmacia_e_por_variacao_minima(): void
    {
        $this->mudanca($this->produto('Da A'), 10.00, 10.50);                     // +5%
        $this->mudanca($this->produto('Da B'), 10.00, 13.00, 1, $this->farmaciaB); // +30%

        $this->assertSame(
            ['Da B'],
            array_column($this->variacoes("farmacia={$this->farmaciaB}")->json('data'), 'descricao')
        );

        $this->assertSame(
            ['Da B'],
            array_column($this->variacoes('variacao_minima=10')->json('data'), 'descricao')
        );
    }

    public function test_periodo_padrao_e_dos_ultimos_trinta_dias(): void
    {
        $this->mudanca($this->produto('Dentro'), 10.00, 11.00, 5);
        $this->mudanca($this->produto('Fora'), 10.00, 11.00, 40);

        $resposta = $this->variacoes()->assertOk();

        $this->assertSame(['Dentro'], array_column($resposta->json('data'), 'descricao'));
        $resposta->assertJsonPath('resumo.inicio', Carbon::today()->subDays(29)->toDateString())
            ->assertJsonPath('resumo.fim', Carbon::today()->toDateString());

        $inicio = Carbon::today()->subDays(60)->toDateString();
        $this->assertCount(2, $this->variacoes("data-inicio={$inicio}")->json('data'));
    }

    public function test_periodo_invalido_e_recusado(): void
    {
        $longe = Carbon::today()->subDays(400)->toDateString();
        $this->variacoes("data-inicio={$longe}")->assertStatus(400);

        $this->variacoes('data-inicio=2026-08-10&data-fim=2026-08-01')->assertStatus(400);
        $this->variacoes('tipo=qualquer')->assertStatus(400);
    }

    /**
     * Variacoes iguais de proposito: o empate e onde LIMIT/OFFSET sem
     * desempate repete ou pula linha entre paginas.
     */
    public function test_paginas_nao_repetem_nem_perdem_linha(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->mudanca($this->produto("Produto {$i}"), 10.00, 11.00);
        }

        $primeira = $this->variacoes()->assertOk();
        $primeira->assertJsonPath('total', 120)->assertJsonPath('last_page', 3);

        $ids = [];
        foreach ([1, 2, 3] as $pagina) {
            $ids = array_merge($ids, array_column($this->variacoes("page={$pagina}")->json('data'), 'preco_id'));
        }

        $this->assertCount(120, $ids);
        $this->assertCount(120, array_unique($ids));
    }

    /** O arquivo tem que trazer o mesmo recorte que a tela. */
    public function test_export_csv_traz_as_mesmas_linhas_da_tela(): void
    {
        $this->mudanca($this->produto('Dipirona'), 10.00, 12.50);
        $this->mudanca($this->produto('FABRAZYME'), 41.99, 23070.60);

        $resposta = $this->get('/api/report/export?formato=csv&priceType=variation');
        $resposta->assertOk();
        $csv = $resposta->streamedContent();

        $this->assertStringContainsString('Preço anterior', $csv);
        $this->assertStringContainsString('Variação (%)', $csv);
        $this->assertStringContainsString('10.00', $csv);
        $this->assertStringContainsString('12.50', $csv);
        $this->assertStringContainsString('25.00', $csv);
        $this->assertStringNotContainsString('FABRAZYME', $csv);

        $completo = $this->get('/api/report/export?formato=csv&priceType=variation&incluir_suspeitas=1');
        $this->assertStringContainsString('FABRAZYME', $completo->streamedContent());
    }

    public function test_export_excel_de_variacoes_sai_com_numero_de_verdade(): void
    {
        $this->mudanca($this->produto('Dipirona', '7891234567890'), 10.00, 12.50);

        $resposta = $this->get('/api/report/export?formato=excel&priceType=variation');
        $resposta->assertOk();

        $arquivo = tempnam(sys_get_temp_dir(), 'var') . '.xlsx';
        file_put_contents($arquivo, $resposta->streamedContent());

        $leitor = new \OpenSpout\Reader\XLSX\Reader();
        $leitor->open($arquivo);

        $linhas = [];
        foreach ($leitor->getSheetIterator() as $planilha) {
            foreach ($planilha->getRowIterator() as $linha) {
                $linhas[] = $linha->toArray();
            }
        }
        $leitor->close();
        @unlink($arquivo);

        $this->assertCount(2, $linhas, 'cabeçalho + uma linha de dados');
        $this->assertSame('Variação (%)', $linhas[0][8]);
        $this->assertSame('7891234567890', $linhas[1][3]);
        $this->assertEqualsWithDelta(10.00, $linhas[1][4], 0.001);
        $this->assertEqualsWithDelta(12.50, $linhas[1][6], 0.001);
        $this->assertEqualsWithDelta(25.00, $linhas[1][8], 0.001);
    }

    public function test_export_recusa_periodo_acima_do_teto(): void
    {
        $longe = Carbon::today()->subDays(400)->toDateString();

        $this->get("/api/report/export?formato=csv&priceType=variation&data-inicio={$longe}")
            ->assertStatus(400);
    }
}
