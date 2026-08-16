<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O relatorio de preco atual e a regra de preco-lixo.
 *
 * Duas coisas que andavam separadas e agora sao a mesma: o que a busca
 * considera existente e o que o relatorio entrega. Ate aqui a busca escondia o
 * produto inativo e o relatorio o exportava, entao "desativar" nao tinha efeito
 * nenhum sobre o arquivo que as pessoas de fato abrem.
 */
class RelatorioTest extends TestCase
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

    private function coletar(float $preco): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/coletas', [
            'farmacia_id' => $this->farmaciaId,
            'itens'       => [[
                'produto_id' => $this->produtoId,
                'preco'      => $preco,
                'status'     => 'ok',
            ]],
        ]);
    }

    private function csv(string $extra = ''): string
    {
        $resposta = $this->get("/api/report/export?formato=csv&priceType=current{$extra}");
        $resposta->assertOk();

        return $resposta->streamedContent();
    }

    /**
     * R$ 0,01 nao e preco — e a farmacia dizendo que nao tem. Foi assim que
     * sementes e adubo entraram na base com um centavo.
     */
    public function test_preco_de_um_centavo_nao_vira_registro(): void
    {
        DB::table('informacoes_produtos')->insert([
            'farmacia_id' => $this->farmaciaId,
            'produto_id'  => $this->produtoId,
            'sku'         => 'SKU-1',
            'ativo'       => 1,
        ]);

        $this->coletar(0.01)->assertOk()->assertJsonPath('precos_alterados', 0);

        $this->assertSame(0, DB::table('precos')->count());
        $this->assertSame('preco_invalido', DB::table('informacoes_produtos')->value('ultimo_status'));
        // Falha nao carimba coleta: e assim que o produto envelhece ate sair.
        $this->assertNull(DB::table('informacoes_produtos')->value('ultima_coleta_em'));
        $this->assertSame(1, (int) DB::table('informacoes_produtos')->value('falhas_consecutivas'));
    }

    public function test_preco_valido_acima_do_piso_continua_entrando(): void
    {
        DB::table('informacoes_produtos')->insert([
            'farmacia_id' => $this->farmaciaId,
            'produto_id'  => $this->produtoId,
            'sku'         => 'SKU-1',
            'ativo'       => 1,
        ]);

        $this->coletar(0.02)->assertOk()->assertJsonPath('precos_alterados', 1);

        $this->assertSame(1, DB::table('precos')->count());
    }

    /** Produto que voltou a ter preco de verdade volta ao ar sozinho. */
    public function test_produto_desativado_reativa_quando_o_preco_volta(): void
    {
        DB::table('informacoes_produtos')->insert([
            'farmacia_id'   => $this->farmaciaId,
            'produto_id'    => $this->produtoId,
            'sku'           => 'SKU-1',
            'ativo'         => 0,
            'desativado_em' => now(),
        ]);

        $this->coletar(12.90)->assertOk();

        $info = DB::table('informacoes_produtos')->first();
        $this->assertSame(1, (int) $info->ativo);
        $this->assertNull($info->desativado_em);
    }

    public function test_relatorio_exporta_o_preco_atual(): void
    {
        $this->postJson('/api/precos', [
            'precos' => [[
                'farmacia_id' => $this->farmaciaId,
                'produto_id'  => $this->produtoId,
                'preco'       => 12.90,
                'data'        => '2026-08-01',
            ]],
        ])->assertOk();

        $csv = $this->csv();

        $this->assertStringContainsString('Dipirona Sódica 500mg 20 comprimidos', $csv);
        $this->assertStringContainsString('12.90', $csv);
    }

    /**
     * O ponto da mudanca: desativar tem que tirar do arquivo. Antes o relatorio
     * ignorava `ativo` e entregava o produto morto do mesmo jeito.
     */
    public function test_produto_inativo_fica_de_fora_do_relatorio(): void
    {
        $this->postJson('/api/precos', [
            'precos' => [[
                'farmacia_id' => $this->farmaciaId,
                'produto_id'  => $this->produtoId,
                'preco'       => 12.90,
                'data'        => '2026-08-01',
            ]],
        ])->assertOk();

        DB::table('informacoes_produtos')->insert([
            'farmacia_id' => $this->farmaciaId,
            'produto_id'  => $this->produtoId,
            'sku'         => 'SKU-1',
            'ativo'       => 0,
        ]);

        $this->assertStringNotContainsString('Dipirona', $this->csv());

        // Quem precisa do retrato completo ainda consegue pedir.
        $this->assertStringContainsString('Dipirona', $this->csv('&incluir_inativos'));
    }

    /** Sem registro em informacoes_produtos nao ha o que avaliar: fica. */
    public function test_produto_sem_registro_de_coleta_continua_no_relatorio(): void
    {
        $this->postJson('/api/precos', [
            'precos' => [[
                'farmacia_id' => $this->farmaciaId,
                'produto_id'  => $this->produtoId,
                'preco'       => 12.90,
                'data'        => '2026-08-01',
            ]],
        ])->assertOk();

        $this->assertStringContainsString('Dipirona', $this->csv());
    }

    /**
     * O relatorio percorre a consulta em cursor. Este teste nao mede tempo —
     * mede que nenhuma linha se perdeu ou dobrou na travessia, que era o risco
     * real de trocar a forma de percorrer.
     */
    public function test_relatorio_traz_todas_as_linhas_sem_repetir(): void
    {
        $precos = [];
        for ($i = 1; $i <= 60; $i++) {
            $produtoId = DB::table('produtos')->insertGetId([
                'descricao' => "Produto de teste {$i}",
                'EAN'       => str_pad((string) (7890000000000 + $i), 13, '0', STR_PAD_LEFT),
            ], 'produto_id');

            $precos[] = [
                'farmacia_id' => $this->farmaciaId,
                'produto_id'  => $produtoId,
                // Preços repetidos de propósito: a ordenação é por preço, e o
                // empate é onde uma travessia mal feita perde ou duplica linha.
                'preco'       => 10.00 + ($i % 3),
                'data'        => '2026-08-01',
            ];
        }

        $this->postJson('/api/precos', ['precos' => $precos])->assertOk();

        $linhas = array_filter(explode("\n", trim($this->csv())));

        // 60 produtos + 1 cabeçalho.
        $this->assertCount(61, $linhas);
        $this->assertSame(count($linhas), count(array_unique($linhas)));
    }
}
