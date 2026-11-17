<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * O importador le uma planilha que a Anvisa monta a mao todo mes. Estes testes
 * reproduzem o que ja apareceu nela: aba de capa antes dos dados, cabecalho
 * abaixo de texto solto, EAN como numero e "-" no lugar de EAN ausente.
 */
class ImportarCmedTest extends TestCase
{
    use RefreshDatabase;

    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arquivo = tempnam(sys_get_temp_dir(), 'cmed') . '.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
        parent::tearDown();
    }

    private function planilha(array $linhasDeDados): void
    {
        $writer = new Writer();
        $writer->openToFile($this->arquivo);

        // Aba de capa, como na planilha real.
        $writer->getCurrentSheet()->setName('Cabeçalho');
        $writer->addRow(Row::fromValues(['Lista de preços de medicamentos']));

        $writer->addNewSheetAndMakeItCurrent()->setName('Lista PMC');
        $writer->addRow(Row::fromValues(['Texto de apresentação acima da tabela']));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([
            'SUBSTÂNCIA', 'CNPJ', 'LABORATÓRIO', 'EAN 1', 'EAN 2', 'EAN 3',
            'PRODUTO', 'APRESENTAÇÃO', 'CLASSE TERAPÊUTICA',
        ]));
        foreach ($linhasDeDados as $linha) {
            $writer->addRow(Row::fromValues($linha));
        }

        $writer->close();
    }

    public function test_importa_classe_e_substancia_por_ean(): void
    {
        $this->planilha([
            ['PARACETAMOL;FENILEFRINA', '00', 'EMS', '7891111111111', '7891111111112', '-',
                'RESFENOL', '400 MG CAP', 'R5A - ANTIGRIPAIS SEM ANTIINFECCIOSOS'],
            // EAN gravado como numero na celula.
            ['DIPIRONA', '00', 'SANOFI', 7893333333333.0, '-', '-',
                'NOVALGINA', '500 MG COM', 'N2B2 - ANALGÉSICOS NÃO NARCÓTICOS'],
        ]);

        $this->artisan('cmed:importar', ['arquivo' => $this->arquivo, '--minimo' => 1])
            ->assertSuccessful();

        $this->assertSame(3, DB::table('cmed_medicamentos')->count());
        $this->assertSame('R5A', DB::table('cmed_medicamentos')->where('ean', '7891111111112')->value('classe_codigo'));
        $this->assertSame('N2B2', DB::table('cmed_medicamentos')->where('ean', '7893333333333')->value('classe_codigo'));

        $classe = DB::table('cmed_classes')->where('codigo', 'R5A')->first();
        $this->assertSame('ANTIGRIPAIS SEM ANTIINFECCIOSOS', $classe->nome);
        $this->assertStringContainsString('gripe', $classe->termos);
    }

    public function test_planilha_pequena_demais_nao_apaga_a_lista_atual(): void
    {
        DB::table('cmed_classes')->insert(['codigo' => 'R5A', 'nome' => 'ANTIGRIPAIS']);
        DB::table('cmed_medicamentos')->insert(['ean' => '7890000000001', 'classe_codigo' => 'R5A']);

        $this->planilha([
            ['DIPIRONA', '00', 'SANOFI', '7893333333333', '-', '-', 'NOVALGINA', '500 MG', 'N2B2 - ANALGÉSICOS'],
        ]);

        $this->artisan('cmed:importar', ['arquivo' => $this->arquivo, '--minimo' => 100])
            ->assertFailed();

        $this->assertSame(['7890000000001'], DB::table('cmed_medicamentos')->pluck('ean')->all());
    }

    public function test_reimportar_substitui_a_lista_inteira(): void
    {
        DB::table('cmed_classes')->insert(['codigo' => 'X1', 'nome' => 'CLASSE QUE SAIU']);
        DB::table('cmed_medicamentos')->insert(['ean' => '7890000000001', 'classe_codigo' => 'X1']);

        $this->planilha([
            ['DIPIRONA', '00', 'SANOFI', '7893333333333', '-', '-', 'NOVALGINA', '500 MG', 'N2B2 - ANALGÉSICOS'],
        ]);

        $this->artisan('cmed:importar', ['arquivo' => $this->arquivo, '--minimo' => 1])
            ->assertSuccessful();

        $this->assertSame(['7893333333333'], DB::table('cmed_medicamentos')->pluck('ean')->all());
        $this->assertSame(['N2B2'], DB::table('cmed_classes')->pluck('codigo')->all());
    }
}
