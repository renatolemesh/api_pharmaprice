<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Alvo de linhas por UPDATE da carga inicial. */
    private const LINHAS_POR_LOTE = 60000;

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
     *
     * A migration e re-executavel de proposito. Ela leva minutos numa base de
     * verdade, e a primeira coisa que se faz com uma migration que demora e
     * interrompe-la; cada etapa checa se ja foi feita, e a carga recalcula do
     * log em vez de acumular, entao rodar de novo converge em vez de estragar.
     */
    public function up(): void
    {
        // ALGORITHM=INSTANT: coluna anulavel no fim da tabela nao exige
        // reconstruir 1,86 milhao de linhas. O `AFTER` e omitido de proposito —
        // posicionar a coluna no meio forcaria ALGORITHM=COPY, que reconstroi a
        // tabela inteira e a trava durante isso.
        if (!Schema::hasColumn('precos', 'preco_anterior')) {
            DB::statement('
                ALTER TABLE precos
                    ADD COLUMN preco_anterior DECIMAL(8,2) NULL,
                    ADD COLUMN data_anterior DATE NULL,
                    ALGORITHM=INSTANT
            ');
        }

        // A carga vem ANTES do indice, e a ordem aqui nao e estilo.
        //
        // `preco_anterior` e `data_anterior` fazem parte do indice. Com ele no
        // lugar, cada linha da carga inicial deixa de ser uma escrita e passa a
        // ser tambem um remanejamento de entrada no B-tree — o valor indexado
        // muda em toda linha, entao e apagar e reinserir 1,86 milhao de vezes.
        // Medido na primeira tentativa desta migration, que criava o indice
        // antes: 287 linhas por segundo, com o primeiro UPDATE passando de dez
        // minutos sem terminar. Criar o indice depois, com a coluna ja
        // preenchida, e uma varredura ordenada unica.
        //
        // O drop e incondicional por causa de retomada: se uma tentativa
        // anterior parou depois de criar o indice, deixa-lo no lugar
        // devolveria a lentidao que a ordem existe para evitar. Reconstruir a
        // toa custa uma varredura; carregar com ele custa horas.
        if ($this->temIndice('idx_precos_janela_painel')) {
            Schema::table('precos', function ($table) {
                $table->dropIndex('idx_precos_janela_painel');
            });
        }

        $this->preencher();

        // Indice de cobertura da janela do painel: ele filtra por `data` e le
        // so estas colunas, entao a consulta se resolve inteira no indice, sem
        // ir a tabela uma vez por linha.
        Schema::table('precos', function ($table) {
            $table->index(
                ['data', 'produto_id', 'preco', 'preco_anterior', 'data_anterior'],
                'idx_precos_janela_painel'
            );
        });
    }

    private function temIndice(string $nome): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['precos', $nome]
        )->total > 0;
    }

    /**
     * Carga inicial, em lotes com numero de linhas parecido.
     *
     * A janela e (farmacia_id, produto_id), entao qualquer corte que mantenha
     * pares inteiros do mesmo lado da fronteira produz o mesmo resultado que um
     * UPDATE unico — e uma faixa de produto_id faz isso enquanto ainda usa
     * indice. Cortar por preco_id, que seria o instinto, quebraria: partiria a
     * serie de um par ao meio e o primeiro item de cada pedaco ficaria sem
     * anterior.
     *
     * O que nao da para fazer e supor que faixas iguais de produto_id tenham
     * tamanhos parecidos. Nesta base os ids estao amontoados embaixo: a faixa
     * 1..20000 sozinha guarda 819.495 linhas, 44% da tabela, enquanto
     * 100009..120000 guarda 19.557. Foi assim que a primeira tentativa desta
     * migration virou um UPDATE de 819 mil linhas. As fronteiras abaixo saem da
     * contagem real, entao um lote e um lote em qualquer base.
     */
    private function preencher(): void
    {
        foreach ($this->fronteiras() as [$inicio, $fim]) {
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

    /**
     * Faixas de produto_id com ~LINHAS_POR_LOTE linhas cada.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function fronteiras(): array
    {
        // Uma passada agrupando por produto_id — barata, resolvida no indice —
        // e o suficiente para saber onde cortar. Em cursor, e nao em get(): sao
        // cem mil linhas de resultado, e nenhuma delas precisa existir ao mesmo
        // tempo que as outras.
        $contagens = DB::cursor(
            'SELECT produto_id, COUNT(*) AS linhas
             FROM precos WHERE produto_id IS NOT NULL
             GROUP BY produto_id ORDER BY produto_id'
        );

        $faixas = [];
        $inicio = null;
        $anterior = null;
        $acumulado = 0;

        foreach ($contagens as $linha) {
            $inicio ??= (int) $linha->produto_id;
            $anterior = (int) $linha->produto_id;
            $acumulado += (int) $linha->linhas;

            if ($acumulado >= self::LINHAS_POR_LOTE) {
                $faixas[] = [$inicio, $anterior];
                $inicio = null;
                $acumulado = 0;
            }
        }

        // O resto, que quase nunca fecha um lote cheio.
        if ($inicio !== null) {
            $faixas[] = [$inicio, $anterior];
        }

        return $faixas;
    }

    public function down(): void
    {
        if ($this->temIndice('idx_precos_janela_painel')) {
            Schema::table('precos', function ($table) {
                $table->dropIndex('idx_precos_janela_painel');
            });
        }

        if (Schema::hasColumn('precos', 'preco_anterior')) {
            Schema::table('precos', function ($table) {
                $table->dropColumn(['preco_anterior', 'data_anterior']);
            });
        }
    }
};
