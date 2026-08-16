<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Paginacao do historico.
 *
 * O caso central e o que quebrava em producao: paginando linhas de preco, a
 * serie de um produto era cortada no meio e virava dois cartoes em paginas
 * diferentes. O fixture abaixo reproduz a condicao — um produto com muito mais
 * mudancas de preco do que cabe numa pagina — e cobra que ele saia inteiro.
 */
class HistoricoTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUTOS = 25;
    private const POR_PAGINA = 20;

    /** Mudancas de preco do produto pesado: mais do que a pagina antiga cabia. */
    private const MUDANCAS_DO_PESADO = 140;

    private int $farmaciaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farmaciaId = DB::table('farmacias')->insertGetId([
            'nome_farmacia' => 'Testefarma',
            'url_base'      => 'https://exemplo.test',
        ], 'farmacia_id');

        for ($i = 0; $i < self::PRODUTOS; $i++) {
            $rotulo = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            $produtoId = DB::table('produtos')->insertGetId([
                'descricao'   => "Teste Produto {$rotulo}",
                'EAN'         => '789000000' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'laboratorio' => 'Teste',
            ], 'produto_id');

            // O produto 00 concentra o historico; os outros tem duas mudancas.
            $quantas = $i === 0 ? self::MUDANCAS_DO_PESADO : 2;

            $linhas = [];
            for ($d = 0; $d < $quantas; $d++) {
                $linhas[] = [
                    'preco' => 10 + $d,
                    'data'  => date('Y-m-d', strtotime("2024-01-01 +{$d} days")),
                ];
            }

            $this->registrarPrecos($produtoId, $this->farmaciaId, $linhas);
        }
    }

    /**
     * Grava o historico e a projecao do preco vigente.
     *
     * `precos_atuais` nao e cache: e de onde o endpoint tira a lista de pares
     * (produto, farmacia) da pagina. Inserir so em `precos`, como este fixture
     * fazia, produzia historico que a busca nao enxergava — que e exatamente a
     * falha que aconteceria em producao se a projecao ficasse para tras.
     *
     * @param  array<int, array{preco: float|int, data: string}>  $linhas
     */
    private function registrarPrecos(int $produtoId, int $farmaciaId, array $linhas): void
    {
        foreach ($linhas as $linha) {
            $precoId = DB::table('precos')->insertGetId([
                'farmacia_id' => $farmaciaId,
                'produto_id'  => $produtoId,
                'preco'       => $linha['preco'],
                'data'        => $linha['data'],
            ], 'preco_id');

            DB::table('precos_atuais')->updateOrInsert(
                ['farmacia_id' => $farmaciaId, 'produto_id' => $produtoId],
                [
                    'preco'         => $linha['preco'],
                    'data'          => $linha['data'],
                    'preco_id'      => $precoId,
                    'atualizado_em' => now(),
                ],
            );
        }
    }

    private function pagina(int $n)
    {
        return $this->getJson("/api/precos/historico?descricao=Teste Produto&page={$n}")
            ->assertOk()
            ->json();
    }

    /**
     * O caso que originou a mudanca.
     *
     * O produto com 140 mudancas cabe num cartao so, na pagina em que ele esta —
     * e nao aparece de novo na seguinte com o resto da serie.
     */
    public function test_serie_longa_nao_e_cortada_entre_paginas(): void
    {
        $p1 = $this->pagina(1);
        $p2 = $this->pagina(2);

        $pesado = collect($p1['data'])->firstWhere('descricao', 'Teste Produto 00');

        $this->assertNotNull($pesado, 'O produto 00 deveria abrir a primeira pagina.');
        $this->assertCount(self::MUDANCAS_DO_PESADO, $pesado['precos']);

        $this->assertNull(
            collect($p2['data'])->firstWhere('descricao', 'Teste Produto 00'),
            'A serie nao pode continuar na pagina seguinte.',
        );
    }

    /** Numero de cartoes fixo, qualquer que seja o tamanho das series. */
    public function test_paginas_tem_sempre_a_mesma_quantidade_de_cartoes(): void
    {
        $this->assertCount(self::POR_PAGINA, $this->pagina(1)['data']);
        $this->assertCount(self::PRODUTOS - self::POR_PAGINA, $this->pagina(2)['data']);
    }

    /** `total` conta cartoes; antes contava linhas de preco. */
    public function test_total_conta_cartoes(): void
    {
        $p1 = $this->pagina(1);

        $this->assertSame(self::PRODUTOS, $p1['total']);
        $this->assertSame(2, $p1['last_page']);
        $this->assertSame(self::POR_PAGINA, $p1['per_page']);
    }

    /** Nenhum cartao se repete entre as duas paginas. */
    public function test_paginas_nao_repetem_cartoes(): void
    {
        $chaves = collect(array_merge($this->pagina(1)['data'], $this->pagina(2)['data']))
            ->map(fn ($g) => $g['EAN'] . '-' . $g['nome_farmacia']);

        $this->assertSame(self::PRODUTOS, $chaves->unique()->count());
    }

    /**
     * O recorte de datas vale dentro do cartao.
     *
     * Escolher os grupos pelo periodo e devolver a serie inteira de cada um
     * seria pior que ignorar o filtro: o grafico mostraria pontos fora da janela
     * que o usuario pediu.
     */
    public function test_periodo_corta_a_serie_do_cartao(): void
    {
        $resposta = $this->getJson(
            '/api/precos/historico?descricao=Teste Produto 00&data-inicio=2024-01-01&data-fim=2024-01-10'
        )->assertOk()->json();

        $pesado = $resposta['data'][0];

        $this->assertCount(10, $pesado['precos']);
        $this->assertSame('2024-01-01', $pesado['precos'][0]['data']);
        $this->assertSame('2024-01-10', end($pesado['precos'])['data']);
    }

    /** Uma farmacia so no filtro nao traz cartao de outra. */
    public function test_filtro_de_farmacia_limita_os_cartoes(): void
    {
        $outra = DB::table('farmacias')->insertGetId([
            'nome_farmacia' => 'Outrafarma',
            'url_base'      => 'https://outra.test',
        ], 'farmacia_id');

        $produtoId = DB::table('produtos')->where('descricao', 'Teste Produto 01')->value('produto_id');

        $this->registrarPrecos($produtoId, $outra, [['preco' => 99.90, 'data' => '2024-02-01']]);

        $resposta = $this->getJson("/api/precos/historico?descricao=Teste Produto 01&farmacia={$outra}")
            ->assertOk()->json();

        $this->assertCount(1, $resposta['data']);
        $this->assertSame('Outrafarma', $resposta['data'][0]['nome_farmacia']);
        $this->assertCount(1, $resposta['data'][0]['precos']);
    }
}
