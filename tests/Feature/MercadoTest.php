<?php

namespace Tests\Feature;

use App\Support\PrecoMercado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O resumo de mercado e o endpoint que alimenta o comparador de tabela propria.
 *
 * Os casos de descarte abaixo nao sao inventados: sao os quatro produtos que
 * apareceram na base de producao com precos impossiveis entre si, e foram eles
 * que definiram a regra. Se um dia ela mudar, e aqui que se ve o que se perde.
 */
class MercadoTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, float>  $valores */
    private function precos(array $valores): array
    {
        $saida = [];
        foreach (array_values($valores) as $i => $valor) {
            $saida[] = [
                'farmacia_id'   => $i + 1,
                'nome_farmacia' => 'Rede ' . ($i + 1),
                'preco'         => $valor,
            ];
        }

        return $saida;
    }

    public function test_precos_coerentes_produzem_mediana(): void
    {
        $r = PrecoMercado::resumir($this->precos([10.00, 12.00, 14.00]));

        $this->assertSame('ok', $r['estado']);
        $this->assertSame(12.00, $r['mediana']);
        $this->assertSame(10.00, $r['minimo']);
        $this->assertSame(14.00, $r['maximo']);
        $this->assertSame(3, $r['redes']);
        $this->assertSame([], $r['descartados']);
    }

    /** Uma rede so nao e mercado: devolve o preco e nao opina. */
    public function test_uma_rede_nao_gera_mediana(): void
    {
        $r = PrecoMercado::resumir($this->precos([10.00]));

        $this->assertSame('rede_unica', $r['estado']);
        $this->assertNull($r['mediana']);
        $this->assertSame(10.00, $r['minimo']);
    }

    public function test_duas_redes_proximas_geram_mediana(): void
    {
        $r = PrecoMercado::resumir($this->precos([10.00, 12.00]));

        $this->assertSame('ok', $r['estado']);
        $this->assertSame(11.00, $r['mediana']);
    }

    /** Duas redes muito distantes: nao ha terceiro para desempatar. */
    public function test_duas_redes_distantes_ficam_divergentes(): void
    {
        $r = PrecoMercado::resumir($this->precos([10.00, 90.00]));

        $this->assertSame('divergente', $r['estado']);
        $this->assertNull($r['mediana']);
    }

    /**
     * AVASTIN 400 16ML, medido em producao: a Raia publica R$ 63,19 para um
     * oncologico de dez mil reais. Tres redes concordam, uma nao — a maioria
     * arbitra.
     */
    public function test_caso_avastin_descarta_o_intruso_barato(): void
    {
        $r = PrecoMercado::resumir($this->precos([63.19, 8514.50, 9940.40, 10599.13]));

        $this->assertSame('ok', $r['estado']);
        $this->assertCount(1, $r['descartados']);
        $this->assertSame(63.19, $r['descartados'][0]['preco']);
        $this->assertSame(9940.40, $r['mediana']);
        // O minimo passa a ser o menor preco confiavel, nao o descartado.
        $this->assertSame(8514.50, $r['minimo']);
        $this->assertSame(4, $r['redes']);
        $this->assertSame(3, $r['redes_consideradas']);
    }

    /** Fralda Pampers M 42un a R$ 1,45 na Callfarma, contra 57,95 e 89,90. */
    public function test_caso_fralda_descarta_com_apenas_tres_redes(): void
    {
        $r = PrecoMercado::resumir($this->precos([1.45, 57.95, 89.90]));

        $this->assertSame('ok', $r['estado']);
        $this->assertCount(1, $r['descartados']);
        $this->assertSame(1.45, $r['descartados'][0]['preco']);
        $this->assertSame(73.93, $r['mediana']);
    }

    /**
     * Sal Amargo 15g: 2,96 | 2,99 | 106,90 | 189,90.
     *
     * O caso que derruba a regra ingenua. Os precos formam dois blocos e a
     * mediana cai no vazio entre eles, entao "descarte quem esta longe da
     * mediana" jogaria fora justamente os dois precos certos. Sem maioria, a
     * unica resposta honesta e admitir que nao da para comparar.
     */
    public function test_caso_sal_amargo_nao_escolhe_lado(): void
    {
        $r = PrecoMercado::resumir($this->precos([2.96, 2.99, 106.90, 189.90]));

        $this->assertSame('divergente', $r['estado']);
        $this->assertNull($r['mediana'], 'nao pode inventar mediana entre dois blocos');
        $this->assertSame([], $r['descartados'], 'nao pode escolher um lado por sorteio');
        // Os precos continuam visiveis: quem conhece o produto decide.
        $this->assertSame(4, $r['redes_consideradas']);
    }

    public function test_preco_zerado_ou_negativo_nao_entra_na_conta(): void
    {
        $r = PrecoMercado::resumir($this->precos([0.00, 10.00, 12.00, 14.00]));

        $this->assertSame('ok', $r['estado']);
        $this->assertSame(3, $r['redes']);
        $this->assertSame(12.00, $r['mediana']);
    }

    // ------------------------------------------------------------------
    // Endpoint
    // ------------------------------------------------------------------

    private function semear(string $ean, array $precoPorFarmacia): void
    {
        $produtoId = DB::table('produtos')->insertGetId([
            'descricao' => "Produto {$ean}",
            'EAN'       => $ean,
        ], 'produto_id');

        foreach ($precoPorFarmacia as $nome => $preco) {
            $farmaciaId = DB::table('farmacias')->where('nome_farmacia', $nome)->value('farmacia_id')
                ?? DB::table('farmacias')->insertGetId([
                    'nome_farmacia' => $nome,
                    'url_base'      => 'https://exemplo.test',
                ], 'farmacia_id');

            $this->postJson('/api/precos', [
                'precos' => [[
                    'farmacia_id' => $farmaciaId,
                    'produto_id'  => $produtoId,
                    'preco'       => $preco,
                    'data'        => '2026-08-01',
                ]],
            ])->assertOk();
        }
    }

    public function test_endpoint_devolve_mercado_por_ean(): void
    {
        $this->semear('7891234567890', ['Alfa' => 10.00, 'Beta' => 12.00, 'Gama' => 14.00]);

        $resposta = $this->postJson('/api/precos/mercado', ['eans' => ['7891234567890']])
            ->assertOk()
            ->assertJsonPath('data.7891234567890.mercado.estado', 'ok')
            ->assertJsonPath('data.7891234567890.mercado.redes', 3)
            ->assertJsonCount(3, 'data.7891234567890.precos');

        // Comparacao numerica, e nao assertJsonPath: o JSON serializa 12.00
        // como 12, e a igualdade estrita reprovaria int contra float sem que
        // nada estivesse errado.
        $this->assertEqualsWithDelta(12.0, $resposta->json('data.7891234567890.mercado.mediana'), 0.001);
    }

    /**
     * Planilha que passou pelo Excel perde o zero a esquerda. O casamento
     * ignora zeros dos dois lados, senao o produto existe na base e a tela diz
     * que nao encontrou.
     */
    public function test_ean_com_zero_a_esquerda_casa_do_mesmo_jeito(): void
    {
        $this->semear('07891234567890', ['Alfa' => 10.00, 'Beta' => 12.00]);

        $resposta = $this->postJson('/api/precos/mercado', ['eans' => ['7891234567890']])->assertOk();

        $this->assertEqualsWithDelta(11.0, $resposta->json('data.7891234567890.mercado.mediana'), 0.001);
    }

    /** A resposta vem sob o EAN que o cliente mandou, nao sob o da base. */
    public function test_resposta_usa_o_ean_enviado_pelo_cliente(): void
    {
        $this->semear('7891234567890', ['Alfa' => 10.00, 'Beta' => 12.00]);

        $resposta = $this->postJson('/api/precos/mercado', ['eans' => ['0007891234567890']])->assertOk();

        $this->assertEqualsWithDelta(11.0, $resposta->json('data.0007891234567890.mercado.mediana'), 0.001);
    }

    public function test_ean_desconhecido_simplesmente_nao_volta(): void
    {
        $this->semear('7891234567890', ['Alfa' => 10.00, 'Beta' => 12.00]);

        $resposta = $this->postJson('/api/precos/mercado', [
            'eans' => ['7891234567890', '0000000000000'],
        ])->assertOk();

        $resposta->assertJsonCount(1, 'data');
        $resposta->assertJsonMissingPath('data.0000000000000');
    }

    public function test_produto_inativo_fica_de_fora_do_mercado(): void
    {
        $this->semear('7891234567890', ['Alfa' => 10.00, 'Beta' => 12.00, 'Gama' => 14.00]);

        $produtoId = DB::table('produtos')->where('EAN', '7891234567890')->value('produto_id');
        $farmaciaId = DB::table('farmacias')->where('nome_farmacia', 'Alfa')->value('farmacia_id');

        DB::table('informacoes_produtos')->insert([
            'farmacia_id' => $farmaciaId,
            'produto_id'  => $produtoId,
            'ativo'       => 0,
        ]);

        $this->postJson('/api/precos/mercado', ['eans' => ['7891234567890']])
            ->assertOk()
            ->assertJsonPath('data.7891234567890.mercado.redes', 2);
    }

    public function test_lote_grande_demais_e_recusado(): void
    {
        $this->postJson('/api/precos/mercado', ['eans' => array_fill(0, 1001, '7891234567890')])
            ->assertStatus(422);
    }
}
