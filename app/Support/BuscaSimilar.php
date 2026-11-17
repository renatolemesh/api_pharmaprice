<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Busca por similaridade de texto, para achar o que o LIKE perde.
 *
 * O LIKE so acha a sequencia exata. Quem digita "protetor solar" nao recebe os
 * 54 produtos cadastrados como "PROT SOLAR", e quem digita "prot sol fps 50"
 * nao recebe nada. Aqui cada palavra digitada precisa casar com ALGUMA palavra
 * da descricao por similaridade de trigramas (pg_trgm): "prot" casa com
 * "protetor", "fps 50" casa com "FPS50".
 *
 * Por que trigramas e nao embeddings: medido nesta base, com 105 mil
 * descricoes, trigramas acertaram 98% dos 100 primeiros em "prot sol fps 50";
 * o melhor modelo de embedding testado acertou 87% e o mais conhecido, 33%.
 * Abreviacao e problema de grafia, nao de significado. Significado ("gripe"
 * achar Resfenol) e resolvido pelos grupos da CMED - ver GrupoCmed.
 *
 * O indice vive num Postgres a parte (conexao `busca`), com uma copia de
 * produto_id e descricao. Ele e descartavel: `busca:sincronizar --completo`
 * reconstroi tudo a partir do MySQL.
 */
class BuscaSimilar
{
    public const CONEXAO = 'busca';

    /**
     * Similaridade minima de cada palavra. Calibrado na base: em 0,4,
     * "protetor" ainda alcanca "prot" (4 de 9 trigramas em comum); em 0,45
     * ja perdia parte dos abreviados.
     */
    private const LIMIAR = 0.4;

    /**
     * Teto de produtos devolvidos para a consulta de precos. Busca generica
     * ("creme") casa dezenas de milhares; acima disto o resultado vira lista
     * de IDs grande demais para o IN do MySQL, e ninguem pagina ate la.
     */
    public const LIMITE_PRODUTOS = 3000;

    /**
     * Palavras que nao filtram nada. Sem esta lista, "remedio para gripe"
     * exigiria que a descricao tivesse algo parecido com "para".
     */
    private const IGNORADAS = [
        'a', 'o', 'as', 'os', 'de', 'da', 'do', 'das', 'dos', 'e', 'em', 'no',
        'na', 'nos', 'nas', 'com', 'sem', 'para', 'pra', 'por', 'um', 'uma',
        'remedio', 'remedios', 'medicamento', 'medicamentos',
    ];

    private const TAMANHO_LOTE = 2000;

    /** Cria a tabela e o indice, se faltarem. Idempotente. */
    public static function garantirEsquema(): void
    {
        $db = DB::connection(self::CONEXAO);

        $db->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $db->statement('
            CREATE TABLE IF NOT EXISTS produtos_busca (
                produto_id BIGINT PRIMARY KEY,
                descricao  TEXT NOT NULL,
                texto      TEXT NOT NULL
            )
        ');
        $db->statement('
            CREATE INDEX IF NOT EXISTS idx_produtos_busca_texto
            ON produtos_busca USING gin (texto gin_trgm_ops)
        ');
    }

    /**
     * Copia para o indice os produtos que ainda nao estao nele.
     *
     * Incremental por produto_id: descricao so e escrita na criacao do
     * produto, entao o que importa e o que entrou depois do ultimo id
     * indexado. Se uma sincronizacao falhar, a proxima pega o atraso inteiro,
     * porque parte do maior id que DE FATO chegou ao indice.
     *
     * Com $completo, apaga e refaz tudo - para carga manual no MySQL ou
     * mudanca na normalizacao.
     *
     * @return int produtos indexados
     */
    public static function sincronizar(bool $completo = false): int
    {
        self::garantirEsquema();
        $busca = DB::connection(self::CONEXAO);

        if ($completo) {
            $busca->statement('TRUNCATE produtos_busca');
        }

        $ultimo = (int) $busca->table('produtos_busca')->max('produto_id');
        $total = 0;

        DB::table('produtos')
            ->where('produto_id', '>', $ultimo)
            ->whereNotNull('descricao')
            ->where('descricao', '<>', '')
            ->select('produto_id', 'descricao')
            ->chunkById(self::TAMANHO_LOTE, function ($produtos) use ($busca, &$total) {
                $linhas = [];
                foreach ($produtos as $produto) {
                    $linhas[] = [
                        'produto_id' => $produto->produto_id,
                        'descricao'  => $produto->descricao,
                        'texto'      => self::normalizar($produto->descricao),
                    ];
                }
                $busca->table('produtos_busca')->upsert($linhas, ['produto_id'], ['descricao', 'texto']);
                $total += count($linhas);
            }, 'produto_id');

        return $total;
    }

    /**
     * Versao que nao derruba quem chamou: para rodar logo depois de cadastrar
     * produto. Indice fora do ar nao pode fazer o scraper perder o cadastro;
     * o produto entra na proxima sincronizacao que der certo.
     */
    public static function sincronizarSemFalhar(): void
    {
        try {
            self::sincronizar();
        } catch (Throwable $e) {
            Log::warning('Indice de busca nao sincronizado', ['erro' => $e->getMessage()]);
        }
    }

    /**
     * IDs dos produtos parecidos com o texto, do mais parecido ao menos.
     *
     * Devolve null quando o indice nao responde, para quem chama cair no LIKE.
     * Devolve [] quando o texto nao tem nenhuma palavra que filtre.
     *
     * @return int[]|null
     */
    public static function produtoIds(string $texto, int $limite = self::LIMITE_PRODUTOS): ?array
    {
        $linhas = self::consultar($texto, 'produto_id', $limite);

        return $linhas === null ? null : array_map(fn ($l) => (int) $l->produto_id, $linhas);
    }

    /**
     * Descricoes parecidas com o texto, sem repetir, para o autocomplete.
     *
     * @return string[]|null null quando o indice nao responde
     */
    public static function descricoes(string $texto, int $limite): ?array
    {
        $linhas = self::consultar($texto, 'descricao', $limite);

        return $linhas === null ? null : array_map(fn ($l) => $l->descricao, $linhas);
    }

    /**
     * Uma consulta por similaridade, agrupada pela coluna pedida.
     *
     * Cada palavra vira uma condicao `palavra <% texto` - o operador que usa o
     * indice GIN. A ordem soma a similaridade de todas as palavras, e desempata
     * pela descricao mais curta: entre "Dipirona 500mg" e "Kit Dipirona 500mg +
     * Termometro + Bolsa", quem buscou dipirona quer a primeira.
     */
    private static function consultar(string $texto, string $coluna, int $limite): ?array
    {
        $palavras = self::palavras($texto);
        if (!$palavras) {
            return [];
        }

        $filtro = implode(' AND ', array_fill(0, count($palavras), '? <% texto'));
        $nota = implode(' + ', array_fill(0, count($palavras), 'word_similarity(?, texto)'));

        try {
            $db = DB::connection(self::CONEXAO);
            // Por sessao, e o <% so usa o indice com o limiar ja ajustado.
            $db->statement('SET pg_trgm.word_similarity_threshold = ' . self::LIMIAR);

            return $db->select(
                "SELECT {$coluna}
                 FROM produtos_busca
                 WHERE {$filtro}
                 GROUP BY {$coluna}
                 ORDER BY MAX({$nota}) DESC, MIN(length(texto)), {$coluna}
                 LIMIT ?",
                [...$palavras, ...$palavras, $limite]
            );
        } catch (Throwable $e) {
            Log::warning('Busca por similaridade indisponivel; usando LIKE', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Palavras do texto que filtram algo, ja normalizadas e sem repeticao. */
    public static function palavras(string $texto): array
    {
        $palavras = array_filter(
            explode(' ', self::normalizar($texto)),
            fn ($p) => strlen($p) >= 2 && !in_array($p, self::IGNORADAS, true)
        );

        return array_values(array_unique($palavras));
    }

    /**
     * Forma comparavel de uma descricao.
     *
     * - entidades HTML decodificadas duas vezes: a base tem "&amp;amp;#039"
     *   de scraper que escapou o que ja vinha escapado;
     * - minusculas e sem acento;
     * - pontuacao vira espaco ("prot." = "prot");
     * - numero separado de letra ("fps50" = "fps 50", "200ml" = "200 ml"),
     *   porque o site escreve de um jeito e quem busca, do outro.
     */
    public static function normalizar(string $texto): string
    {
        $texto = html_entity_decode(html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = GrupoCmed::normalizar($texto);
        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto);
        $texto = preg_replace('/(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/', ' ', $texto);

        return trim(preg_replace('/\s+/', ' ', $texto));
    }
}
