<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preco imediatamente anterior, gravado na propria linha de `precos`.
     *
     * O painel responde perguntas do tipo "quantos precos subiram esta semana",
     * e para isso precisa, de cada mudanca, do valor que vigorava antes dela.
     * Ate aqui isso era recalculado a cada requisicao por uma subconsulta
     * correlacionada:
     *
     *     JOIN precos p2 ON ... AND p2.data = (
     *         SELECT MAX(p3.data) FROM precos p3
     *         WHERE p3.produto_id = p1.produto_id
     *           AND p3.farmacia_id = p1.farmacia_id AND p3.data < p1.data)
     *
     * Medido em producao: 3,6s por periodo, e `getStatistics` roda dois
     * periodos (atual e anterior) para calcular a variacao — 7,2s so nisso.
     * Reescrever a consulta nao resolve: testei LATERAL (3,3s), subconsulta
     * escalar por preco_id (2,8s) e LAG sobre os pares afetados (8,2s). O piso
     * e estrutural, porque em todas elas o custo e ir buscar a linha anterior
     * de 19 mil pares, uma a uma.
     *
     * O dado nao precisava ser buscado: `precos` e um log de mudancas, e quem
     * insere uma mudanca ja tem em maos o valor que ela substitui — e
     * exatamente o que esta em `precos_atuais` no instante do insert. E o mesmo
     * movimento que criou `precos_atuais`: nao recalcular na leitura o que o
     * escritor ja sabia.
     *
     * Duas consequencias que nao sao so de desempenho:
     *
     *   - A versao antiga casava por `data`. Dois precos do mesmo produto na
     *     mesma data faziam o JOIN casar as duas linhas, contando a mudanca em
     *     duplicidade; medido na base de producao, isso inflava os aumentos de
     *     10.392 para 10.455. Aqui a ligacao e por `preco_id`, que nao empata.
     *   - `preco_anterior IS NULL` passa a significar "primeiro preco conhecido
     *     deste par", que e uma informacao que antes se perdia.
     *
     * Quem escreve: ColetaController e PrecoController. Se divergir do log,
     * `php artisan precos:reconciliar` reconstroi — ele confere as duas
     * projecoes.
     */
    public function up(): void
    {
        // ALGORITHM=INSTANT: coluna anulavel no fim da tabela nao exige
        // reconstruir 1,86 milhao de linhas. Sem isto o ALTER travaria a tabela
        // por minutos. O `AFTER` e omitido de proposito — posicionar a coluna
        // no meio forcaria ALGORITHM=COPY e devolveria a trava.
        DB::statement('
            ALTER TABLE precos
                ADD COLUMN preco_anterior DECIMAL(8,2) NULL,
                ADD COLUMN data_anterior DATE NULL,
                ALGORITHM=INSTANT
        ');

        // Indice de cobertura da janela do painel: ele filtra por `data` e le
        // so estas colunas, entao a consulta se resolve inteira no indice, sem
        // ir a tabela uma vez por linha.
        Schema::table('precos', function ($table) {
            $table->index(
                ['data', 'produto_id', 'preco', 'preco_anterior', 'data_anterior'],
                'idx_precos_janela_painel'
            );
        });

        $this->preencher();
    }

    /**
     * Carga inicial em lotes por faixa de produto_id.
     *
     * A janela e (farmacia_id, produto_id), entao qualquer corte que mantenha
     * pares inteiros do mesmo lado da fronteira produz o mesmo resultado que um
     * UPDATE unico — e uma faixa de produto_id faz isso enquanto ainda usa
     * indice. Cortar por preco_id, que seria o instinto, quebraria: partiria a
     * serie de um par ao meio e o primeiro item de cada pedaco ficaria sem
     * anterior.
     */
    private function preencher(): void
    {
        $maxProduto = (int) DB::table('precos')->max('produto_id');
        if ($maxProduto === 0) {
            return;
        }

        $passo = 20000;

        for ($inicio = 1; $inicio <= $maxProduto; $inicio += $passo) {
            $fim = $inicio + $passo - 1;

            DB::statement('
                UPDATE precos p
                JOIN (
                    SELECT preco_id,
                           LAG(preco) OVER w AS anterior_preco,
                           LAG(data)  OVER w AS anterior_data
                    FROM precos
                    WHERE produto_id BETWEEN ? AND ?
                    WINDOW w AS (PARTITION BY farmacia_id, produto_id ORDER BY preco_id)
                ) ult ON ult.preco_id = p.preco_id
                SET p.preco_anterior = ult.anterior_preco,
                    p.data_anterior  = ult.anterior_data
            ', [$inicio, $fim]);
        }
    }

    public function down(): void
    {
        Schema::table('precos', function ($table) {
            $table->dropIndex('idx_precos_janela_painel');
            $table->dropColumn(['preco_anterior', 'data_anterior']);
        });
    }
};
