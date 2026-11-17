<?php

namespace Tests\Feature;

use App\Support\GrupoCmed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Busca por grupo: "gripe" tem que trazer o Resfenol, que nao tem gripe no
 * nome, e nao pode trazer remedio de insonia para quem buscou "dor".
 */
class GrupoTest extends TestCase
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

        DB::table('cmed_classes')->insert([
            ['codigo' => 'R5A', 'nome' => 'ANTIGRIPAIS SEM ANTIINFECCIOSOS', 'termos' => GrupoCmed::termosDe('R5A')],
            ['codigo' => 'N2B2', 'nome' => 'ANALGÉSICOS NÃO NARCÓTICOS E ANTIPIRÉTICOS ISENTOS DE PRESCRIÇÃO', 'termos' => GrupoCmed::termosDe('N2B2')],
            ['codigo' => 'N5B1', 'nome' => 'HIPNÓTICOS E SEDATIVOS NÃO BARBITÚRICOS PUROS', 'termos' => GrupoCmed::termosDe('N5B1')],
            ['codigo' => 'K1A1', 'nome' => 'SOLUÇÕES ELETROLÍTICAS 1/1', 'termos' => GrupoCmed::termosDe('K1A1')],
        ]);

        $this->produto('Resfenol Paracetamol 400mg 20 cápsulas', '7891111111111', 'R5A', 12.50);
        $this->produto('Cimegripe 20 cápsulas', '7892222222222', 'R5A', 9.90);
        $this->produto('Dipirona Sódica 500mg 10 comprimidos', '7893333333333', 'N2B2', 4.00);
        $this->produto('Zolpidem 10mg 30 comprimidos', '7894444444444', 'N5B1', 30.00);
        // Sem EAN na CMED: segue aparecendo na busca por texto.
        $this->produto('Chá para gripe natural 10 sachês', '1234567', null, 6.00);
    }

    private function produto(string $descricao, string $ean, ?string $classe, float $preco): void
    {
        $id = DB::table('produtos')->insertGetId([
            'descricao' => $descricao,
            'EAN'       => $ean,
        ], 'produto_id');

        DB::table('precos_atuais')->insert([
            'farmacia_id' => $this->farmacia,
            'produto_id'  => $id,
            'preco'       => $preco,
            'data'        => '2026-11-01',
        ]);

        if ($classe) {
            DB::table('cmed_medicamentos')->insert([
                'ean'           => $ean,
                'classe_codigo' => $classe,
                'substancia'    => 'TESTE',
            ]);
        }
    }

    private function descricoes($resposta): array
    {
        return collect($resposta->json('data'))->pluck('descricao')->sort()->values()->all();
    }

    public function test_termos_herdam_dos_prefixos_do_codigo(): void
    {
        $this->assertSame(['N2B2', 'N2B', 'N2', 'N'], GrupoCmed::prefixos('N2B2'));
        // A Anvisa publica o mesmo nivel com e sem zero.
        $this->assertSame(['J7A1', 'J7A', 'J7', 'J'], GrupoCmed::prefixos('J07A1'));
        $this->assertSame(['FITO'], GrupoCmed::prefixos('FITO'));

        // N2B2 nao tem linha propria no arquivo: herda de N2B.
        $this->assertStringContainsString('dor de cabeça', GrupoCmed::termosDe('N2B2'));
    }

    public function test_gripe_encontra_o_grupo_dos_antigripais(): void
    {
        $this->assertSame(['R5A'], GrupoCmed::codigosPara('gripe'));
        $this->assertSame(['R5A'], GrupoCmed::codigosPara('GRIPE'));
    }

    public function test_termo_casa_palavra_inteira(): void
    {
        // "dor" nao pode alcancar o grupo de insonia so porque "dormir" esta
        // nos termos dele.
        $this->assertSame(['N2B2'], GrupoCmed::codigosPara('dor'));
        $this->assertSame(['N5B1'], GrupoCmed::codigosPara('dormir'));
    }

    public function test_remedio_para_no_comeco_e_ignorado(): void
    {
        $this->assertSame(['R5A'], GrupoCmed::codigosPara('remédio para gripe'));
        $this->assertSame(['N2B2'], GrupoCmed::codigosPara('medicamento pra dor de cabeça'));
        $this->assertSame([], GrupoCmed::codigosPara('remédio'));
    }

    public function test_acento_nao_importa(): void
    {
        $this->assertSame(['N2B2'], GrupoCmed::codigosPara('dor de cabeca'));
        $this->assertSame(['N2B2'], GrupoCmed::codigosPara('Dor de Cabeça'));
    }

    public function test_nome_oficial_casa_por_inicio_de_palavra_a_partir_de_cinco_letras(): void
    {
        $this->assertSame(['N2B2'], GrupoCmed::codigosPara('analgésico'));
        // Quatro letras e curto demais para o nome: "anti" casaria meia lista.
        $this->assertSame([], GrupoCmed::codigosPara('anal'));
    }

    public function test_grupos_lista_so_o_que_tem_produto_na_base(): void
    {
        $codigos = collect($this->getJson('/api/grupos')->assertOk()->json())->pluck('codigo');

        $this->assertEqualsCanonicalizing(['R5A', 'N2B2', 'N5B1'], $codigos->all());
        // O grupo com mais produtos vem primeiro.
        $this->assertSame('R5A', $codigos->first());
    }

    public function test_grupos_filtra_pelo_texto(): void
    {
        $this->getJson('/api/grupos?q=gripe')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.codigo', 'R5A')
            ->assertJsonPath('0.produtos', 2);

        $this->getJson('/api/grupos?q=xyzabc')->assertOk()->assertJsonCount(0);
    }

    public function test_precos_filtra_por_grupo(): void
    {
        $resposta = $this->getJson('/api/precos?grupo=R5A')->assertOk();

        $this->assertSame(
            ['Cimegripe 20 cápsulas', 'Resfenol Paracetamol 400mg 20 cápsulas'],
            $this->descricoes($resposta)
        );
        $resposta->assertJsonPath('data.0.grupo', 'R5A');
    }

    public function test_varios_grupos_separados_por_virgula(): void
    {
        $resposta = $this->getJson('/api/precos?grupo=r5a,N2B2')->assertOk();

        $this->assertSame(3, $resposta->json('total'));
    }

    public function test_busca_por_texto_continua_igual_sem_o_parametro(): void
    {
        // Sem incluir_grupos, "gripe" so acha quem tem gripe no nome.
        $resposta = $this->getJson('/api/precos?descricao=gripe')->assertOk();

        $this->assertSame(
            ['Chá para gripe natural 10 sachês', 'Cimegripe 20 cápsulas'],
            $this->descricoes($resposta)
        );
    }

    public function test_incluir_grupos_soma_o_grupo_a_busca_por_texto(): void
    {
        $resposta = $this->getJson('/api/precos?descricao=gripe&incluir_grupos=1')->assertOk();

        // O Resfenol entra pelo grupo; o cha entra pelo texto, mesmo sem EAN
        // na CMED; o Cimegripe casa pelos dois e aparece uma vez so.
        $this->assertSame(
            ['Chá para gripe natural 10 sachês', 'Cimegripe 20 cápsulas', 'Resfenol Paracetamol 400mg 20 cápsulas'],
            $this->descricoes($resposta)
        );
        $this->assertSame(3, $resposta->json('total'));
    }

    public function test_incluir_grupos_sem_grupo_correspondente_e_busca_por_texto(): void
    {
        $resposta = $this->getJson('/api/precos?descricao=zolpidem&incluir_grupos=1')->assertOk();

        $this->assertSame(['Zolpidem 10mg 30 comprimidos'], $this->descricoes($resposta));
    }

    public function test_grupo_combina_com_a_descricao(): void
    {
        $resposta = $this->getJson('/api/precos?descricao=resfenol&grupo=R5A')->assertOk();

        $this->assertSame(['Resfenol Paracetamol 400mg 20 cápsulas'], $this->descricoes($resposta));
    }
}
