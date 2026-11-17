<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schema do PharmaPrice no Postgres, de uma vez.
     *
     * Substitui as migrations da epoca do MySQL (2024-06 a 2026-11), que
     * estavam presas a ele: ALTER ... ALGORITHM=INPLACE, UPDATE com JOIN,
     * information_schema.statistics, ON DUPLICATE KEY. Elas continuam no
     * historico do git; o que esta aqui e o estado em que elas deixaram a base
     * de producao, conferido com SHOW CREATE TABLE em 2026-11-17 - inclusive os
     * indices que tinham sido criados a mao e nao estavam em migration nenhuma.
     *
     * Os dados vem do MySQL pelo `php artisan banco:copiar-do-mysql`.
     */
    public function up(): void
    {
        // pg_trgm: indice que atende LIKE '%...%' e a busca por semelhanca.
        // unaccent: a busca ignora acento, como o utf8mb4_unicode_ci fazia.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        /*
         * Forma comparavel de uma descricao, para busca:
         *
         *   - entidades HTML viram espaco, com "&amp;" desfeito duas vezes
         *     antes: a base tem "&amp;amp;#039;" de scraper que escapou o que
         *     ja vinha escapado;
         *   - sem acento e em minusculas;
         *   - o que nao e letra nem numero vira espaco ("prot." = "prot");
         *   - numero separado de letra ("fps50" = "fps 50", "200ml" =
         *     "200 ml"), porque o site escreve de um jeito e quem busca, do
         *     outro.
         *
         * Em SQL, e nao em PHP, para que a descricao gravada e o texto buscado
         * passem pela mesma funcao: BuscaSimilar normaliza a busca chamando
         * esta mesma funcao.
         *
         * IMMUTABLE e o que permite usa-la em coluna gerada. unaccent() sozinha
         * e STABLE porque o dicionario poderia mudar; passando o dicionario
         * explicitamente, o resultado so depende da entrada.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION normalizar_busca(texto text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE RETURNS NULL ON NULL INPUT
            AS $$
                SELECT btrim(
                    regexp_replace(
                        regexp_replace(
                            regexp_replace(
                                lower(public.unaccent('public.unaccent'::regdictionary,
                                    regexp_replace(
                                        replace(replace(texto, '&amp;', '&'), '&amp;', '&'),
                                        '&(#[0-9]+|#x[0-9a-f]+|[a-z]+);', ' ', 'gi'))),
                                '[^a-z0-9]+', ' ', 'g'),
                            '([a-z])([0-9])', '\1 \2', 'g'),
                        '([0-9])([a-z])', '\1 \2', 'g'))
            $$
        SQL);

        Schema::create('farmacias', function (Blueprint $table) {
            $table->id('farmacia_id');
            $table->string('nome_farmacia', 15)->nullable();
            $table->string('url_base', 100)->nullable();
        });

        Schema::create('produtos', function (Blueprint $table) {
            $table->id('produto_id');
            $table->string('descricao', 255)->nullable();
            // Maiusculo por herança do MySQL, e mantido: e o nome que a API
            // devolve no JSON. Em SQL escrito a mao, vai entre aspas: "EAN".
            $table->string('EAN', 15)->nullable()->unique();
            $table->string('laboratorio', 50)->nullable();
            $table->index('descricao', 'idx_produtos_descricao');
        });

        // Coluna gerada: sempre igual a normalizar_busca(descricao), sem
        // ninguem precisar lembrar de atualiza-la.
        DB::statement('ALTER TABLE produtos ADD COLUMN texto_busca text GENERATED ALWAYS AS (normalizar_busca(descricao)) STORED');
        DB::statement('CREATE INDEX idx_produtos_texto_busca ON produtos USING gin (texto_busca gin_trgm_ops)');

        Schema::create('informacoes_produtos', function (Blueprint $table) {
            $table->id('informacao_id');
            $table->unsignedBigInteger('farmacia_id')->nullable();
            $table->unsignedBigInteger('produto_id')->nullable();
            $table->string('link', 255)->nullable();
            $table->string('sku', 50)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamp('ultima_coleta_em')->nullable();
            $table->timestamp('ultima_tentativa_em')->nullable();
            $table->unsignedInteger('falhas_consecutivas')->default(0);
            $table->string('ultimo_status', 30)->nullable();
            $table->timestamp('desativado_em')->nullable();

            $table->unique(['farmacia_id', 'produto_id']);
            $table->index(['produto_id', 'farmacia_id'], 'idx_info_produto_farmacia');
            $table->index(['ativo', 'ultima_coleta_em'], 'idx_ip_ativo_coleta');
            $table->index(['farmacia_id', 'ultima_coleta_em'], 'idx_ip_farmacia_coleta');
            $table->index(['farmacia_id', 'sku'], 'idx_ip_farmacia_sku');
            $table->index(['farmacia_id', 'link'], 'idx_ip_farmacia_link');

            $table->foreign('farmacia_id')->references('farmacia_id')->on('farmacias')->onDelete('cascade');
            $table->foreign('produto_id')->references('produto_id')->on('produtos')->onDelete('cascade');
        });

        // Fila de descoberta de links dos scrapers.
        Schema::create('links', function (Blueprint $table) {
            $table->unsignedBigInteger('farmacia_id')->nullable();
            $table->string('link', 255)->nullable();
            // O MySQL cria indice para toda chave estrangeira; o Postgres nao.
            $table->index('farmacia_id', 'idx_links_farmacia');
            $table->foreign('farmacia_id')->references('farmacia_id')->on('farmacias')->onDelete('cascade');
        });

        // Log de mudancas de preco. `preco_anterior`/`data_anterior` sao o
        // valor que cada mudanca substituiu, gravado por quem insere.
        Schema::create('precos', function (Blueprint $table) {
            $table->id('preco_id');
            $table->unsignedBigInteger('farmacia_id')->nullable();
            $table->unsignedBigInteger('produto_id')->nullable();
            $table->decimal('preco', 8, 2)->nullable();
            $table->date('data')->nullable();
            $table->decimal('preco_anterior', 8, 2)->nullable();
            $table->date('data_anterior')->nullable();

            $table->index(['farmacia_id', 'produto_id', 'data'], 'idx_precos_farmacia_produto_data');
            $table->index(['produto_id', 'farmacia_id', 'data'], 'idx_precos_produto_farmacia_data');
            $table->index(['data', 'farmacia_id'], 'idx_precos_data_farmacia');

            $table->foreign('farmacia_id')->references('farmacia_id')->on('farmacias')->onDelete('cascade');
            $table->foreign('produto_id')->references('produto_id')->on('produtos')->onDelete('cascade');
        });

        // Os dois indices de cobertura do MySQL, com INCLUDE: as colunas que
        // so sao lidas ficam na folha e nao pesam na ordenacao.
        DB::statement('CREATE INDEX idx_precos_covering ON precos (produto_id, farmacia_id, preco_id DESC) INCLUDE (preco, data)');
        DB::statement('CREATE INDEX idx_precos_janela_painel ON precos (data, produto_id) INCLUDE (preco, preco_anterior, data_anterior)');

        // Preco atual de cada par: a projecao de `precos` que a busca le.
        Schema::create('precos_atuais', function (Blueprint $table) {
            $table->unsignedBigInteger('farmacia_id');
            $table->unsignedBigInteger('produto_id');
            $table->decimal('preco', 8, 2);
            $table->date('data')->nullable();
            $table->unsignedBigInteger('preco_id')->nullable();
            $table->timestamp('atualizado_em')->nullable();

            $table->primary(['farmacia_id', 'produto_id']);
            $table->index('produto_id', 'idx_precos_atuais_produto');
            $table->index('preco', 'idx_precos_atuais_preco');

            $table->foreign('farmacia_id')->references('farmacia_id')->on('farmacias')->onDelete('cascade');
            $table->foreign('produto_id')->references('produto_id')->on('produtos')->onDelete('cascade');
        });

        Schema::create('execucoes_coleta', function (Blueprint $table) {
            $table->id('execucao_id');
            $table->unsignedBigInteger('farmacia_id');
            $table->string('script', 50)->nullable();
            $table->timestamp('iniciado_em')->nullable();
            $table->timestamp('finalizado_em')->nullable();
            $table->unsignedInteger('itens_vistos')->default(0);
            $table->unsignedInteger('itens_com_preco')->default(0);
            $table->unsignedInteger('precos_alterados')->default(0);
            $table->unsignedInteger('links_atualizados')->default(0);
            $table->unsignedInteger('skus_atualizados')->default(0);
            $table->unsignedInteger('nao_resolvidos')->default(0);
            $table->unsignedInteger('erros')->default(0);
            $table->string('status', 20)->default('rodando');
            $table->text('observacao')->nullable();

            $table->index(['farmacia_id', 'status', 'finalizado_em'], 'idx_execucoes_farmacia_status');
            $table->index('iniciado_em', 'idx_execucoes_iniciado');
            $table->foreign('farmacia_id')->references('farmacia_id')->on('farmacias')->onDelete('cascade');
        });

        // Classe terapeutica por EAN, da lista da CMED (cmed:importar).
        Schema::create('cmed_classes', function (Blueprint $table) {
            // Codigo como a Anvisa publica: "R5A", "N2B2", "J07A1".
            $table->string('codigo', 10)->primary();
            $table->string('nome', 200);
            // Termos leigos, separados por virgula ("gripe, resfriado").
            // Vem de database/data/cmed_termos.php, nao da planilha.
            $table->string('termos', 500)->nullable();
        });

        Schema::create('cmed_medicamentos', function (Blueprint $table) {
            // Mesmo tipo de produtos."EAN", que e por onde as duas se juntam.
            $table->string('ean', 15)->primary();
            $table->string('classe_codigo', 10)->nullable();
            $table->text('substancia')->nullable();
            $table->string('laboratorio', 255)->nullable();
            $table->string('produto', 255)->nullable();
            $table->text('apresentacao')->nullable();

            $table->index('classe_codigo', 'idx_cmed_medicamentos_classe');
        });
    }

    public function down(): void
    {
        foreach (['cmed_medicamentos', 'cmed_classes', 'execucoes_coleta', 'precos_atuais', 'precos',
                  'links', 'informacoes_produtos', 'produtos', 'farmacias'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
        DB::statement('DROP FUNCTION IF EXISTS normalizar_busca(text)');
    }
};
