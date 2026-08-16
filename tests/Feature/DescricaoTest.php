<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O autocomplete da busca.
 *
 * O caso que originou estes testes e real: "Formula Infantil Nan Sem Lactose
 * 400g" existe duas vezes na base, com EAN suico e europeu, e a lista mostrava
 * as duas linhas — texto identico, mesmo resultado ao clicar. Na producao sao
 * 1.825 descricoes repetidas, uma delas em 12 linhas.
 */
class DescricaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O endpoint guarda a resposta por 24h; sem limpar, o segundo teste le
        // o resultado do primeiro e passa sem executar consulta nenhuma.
        Cache::flush();
    }

    private function produto(string $descricao, string $ean): void
    {
        DB::table('produtos')->insert([
            'descricao'   => $descricao,
            'EAN'         => $ean,
            'laboratorio' => 'Teste',
        ]);
    }

    public function test_descricao_repetida_aparece_uma_vez_so(): void
    {
        $this->produto('Formula Infantil Nan Sem Lactose 400g', '7613034909480');
        $this->produto('Formula Infantil Nan Sem Lactose 400g', '8445290273758');

        $resposta = $this->getJson('/api/descricoes?descricao=Nan Sem Lactose');

        $resposta->assertOk();
        $this->assertCount(1, $resposta->json());
    }

    /** Produtos diferentes continuam sendo sugestoes diferentes. */
    public function test_descricoes_distintas_nao_sao_colapsadas(): void
    {
        $this->produto('Dipirona Sodica 500mg 20 comprimidos', '7891234567890');
        $this->produto('Dipirona Sodica 500mg 10 comprimidos', '7891234567891');

        $resposta = $this->getJson('/api/descricoes?descricao=Dipirona');

        $resposta->assertOk();
        $this->assertCount(2, $resposta->json());
    }

    /**
     * Sem filtro a rota devolvia a tabela inteira — 135 mil linhas em producao.
     * O piso de caracteres do front nao protege quem chama a API direto.
     */
    public function test_resposta_tem_teto_mesmo_sem_filtro(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->produto('Produto ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), '789123456' . str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $resposta = $this->getJson('/api/descricoes');

        $resposta->assertOk();
        $this->assertCount(30, $resposta->json());
    }

    /** A ordem precisa ser estavel: a sugestao nao pode dancar sob o cursor. */
    public function test_ordem_e_alfabetica(): void
    {
        $this->produto('Zetia Ezetimiba 10mg', '7891234567892');
        $this->produto('Amoxil Amoxicilina 500mg', '7891234567893');

        $descricoes = array_column($this->getJson('/api/descricoes?descricao=m')->json(), 'descricao');

        $ordenadas = $descricoes;
        sort($ordenadas);

        $this->assertSame($ordenadas, $descricoes);
    }
}
