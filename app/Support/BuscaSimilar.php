<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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
 * Compara contra `produtos.texto_busca`, coluna gerada pelo proprio Postgres
 * com normalizar_busca(descricao) e indexada com gin_trgm_ops (ver a migration
 * do schema). Por ser gerada, nunca fica atrasada em relacao a descricao.
 */
class BuscaSimilar
{
    /**
     * Similaridade minima de cada palavra. Calibrado na base: em 0,4,
     * "protetor" ainda alcanca "prot" (4 de 9 trigramas em comum); em 0,45
     * ja perdia parte dos abreviados.
     */
    private const LIMIAR = 0.4;

    /**
     * Palavras que nao filtram nada. Sem esta lista, "remedio para gripe"
     * exigiria que a descricao tivesse algo parecido com "para".
     */
    private const IGNORADAS = [
        'a', 'o', 'as', 'os', 'de', 'da', 'do', 'das', 'dos', 'e', 'em', 'no',
        'na', 'nos', 'nas', 'com', 'sem', 'para', 'pra', 'por', 'um', 'uma',
        'remedio', 'remedios', 'medicamento', 'medicamentos',
    ];

    /**
     * Restringe a consulta aos produtos parecidos com o texto.
     *
     * Cada palavra vira `palavra <% texto_busca`, o operador que usa o indice
     * GIN. Texto sem nenhuma palavra que filtre ("de", "a") nao acha nada, em
     * vez de achar tudo.
     *
     * @param string $alias alias de `produtos` na consulta
     */
    public static function filtrar(Builder $query, string $alias, string $texto): void
    {
        $palavras = self::palavras($texto);

        if (!$palavras) {
            $query->whereRaw('false');
            return;
        }

        self::ajustarLimiar();

        foreach ($palavras as $palavra) {
            $query->whereRaw("? <% {$alias}.texto_busca", [$palavra]);
        }
    }

    /**
     * Descricoes parecidas com o texto, sem repetir, para o autocomplete.
     *
     * A ordem soma a similaridade de todas as palavras e desempata pela
     * descricao mais curta: entre "Dipirona 500mg" e "Kit Dipirona 500mg +
     * Termometro + Bolsa", quem buscou dipirona quer a primeira.
     *
     * @return string[]
     */
    public static function descricoes(string $texto, int $limite): array
    {
        $palavras = self::palavras($texto);
        if (!$palavras) {
            return [];
        }

        $query = DB::table('produtos as p')->whereNotNull('p.descricao');
        self::filtrar($query, 'p', $texto);

        $nota = implode(' + ', array_fill(0, count($palavras), 'word_similarity(?, p.texto_busca)'));

        return $query
            ->groupBy('p.descricao')
            ->orderByRaw("MAX({$nota}) DESC", $palavras)
            ->orderByRaw('MIN(length(p.texto_busca))')
            ->orderBy('p.descricao')
            ->limit($limite)
            ->pluck('p.descricao')
            ->all();
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
     * Forma comparavel de um texto: a mesma funcao do Postgres que gera
     * `produtos.texto_busca`, para busca e descricao nunca divergirem.
     */
    public static function normalizar(string $texto): string
    {
        return (string) DB::scalar('SELECT normalizar_busca(?)', [$texto]);
    }

    /**
     * O `<%` le o limiar da sessao, e so usa o indice com ele ja ajustado.
     * Uma vez por conexao basta.
     */
    private static function ajustarLimiar(): void
    {
        static $conexao = null;

        $pdo = DB::connection()->getPdo();
        if ($conexao !== $pdo) {
            DB::statement('SET pg_trgm.word_similarity_threshold = ' . self::LIMIAR);
            $conexao = $pdo;
        }
    }
}
