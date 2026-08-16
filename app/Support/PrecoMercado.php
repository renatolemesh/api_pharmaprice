<?php

namespace App\Support;

/**
 * Resume os precos de um produto nas varias redes num numero comparavel.
 *
 * Parece so calcular mediana, mas o trabalho de verdade e decidir QUANDO nao
 * calcular. A base tem preco errado: mesmo EAN, valores impossiveis entre si.
 * Quatro casos reais medidos em producao:
 *
 *   AVASTIN 400 16ML   Raia 63,19   | 8.514 | 9.940 | 10.599
 *   EXJADE 500MG       Raia 41,99   | 4.249 | 5.120 | 5.120
 *   Fralda Pampers M   Callfarma 1,45 | 57,95 | 89,90
 *   Sal Amargo 15g     2,96 | 2,99 | 106,90 | 189,90
 *
 * Nos tres primeiros ha um intruso obvio e os demais concordam — da para
 * descartar e seguir. O quarto e diferente: dois contra dois, e a mediana cai
 * no vazio entre os grupos. Uma regra ingenua de "descarte quem esta longe da
 * mediana" descartaria justamente os dois precos certos.
 *
 * Por isso sao tres estados, e nao dois. Quando os precos discordam em bloco, a
 * resposta certa e dizer que nao da para comparar — e nao escolher um lado. Um
 * comparador que erra com confianca e pior que um que se cala.
 */
class PrecoMercado
{
    /**
     * Quantas vezes a mediana um preco precisa ser para virar suspeito.
     *
     * Redes brigam por margem, e diferenca de 100% no mesmo EAN existe. Mas 4x
     * nao e concorrencia: e caixa com 30 unidades cadastrada no codigo da caixa
     * com 1, ou preco que o site nao publicou e o scraper leu como qualquer
     * coisa.
     */
    private const FATOR_SUSPEITO = 4.0;

    /**
     * Acima disto, dois precos nao descrevem o mesmo produto.
     *
     * Usado so quando ha duas redes: nao existe mediana capaz de arbitrar entre
     * dois valores, entao a saida e admitir a divergencia.
     */
    private const FATOR_DIVERGENTE = 3.0;

    /** Minimo de redes para que a mediana signifique alguma coisa. */
    private const MINIMO_PARA_MEDIANA = 3;

    /**
     * @param  array<int, array{farmacia_id: int, nome_farmacia: string, preco: float|string}>  $precos
     * @return array{
     *     estado: string,
     *     mediana: float|null,
     *     minimo: float|null,
     *     maximo: float|null,
     *     redes: int,
     *     redes_consideradas: int,
     *     descartados: array<int, array{farmacia_id: int, nome_farmacia: string, preco: float}>
     * }
     */
    public static function resumir(array $precos): array
    {
        $limpos = [];
        foreach ($precos as $item) {
            $valor = (float) ($item['preco'] ?? 0);
            if ($valor > 0) {
                $limpos[] = [
                    'farmacia_id'   => (int) $item['farmacia_id'],
                    'nome_farmacia' => (string) $item['nome_farmacia'],
                    'preco'         => $valor,
                ];
            }
        }

        $total = count($limpos);

        if ($total === 0) {
            return self::resposta('sem_dados', null, [], 0, []);
        }

        // Uma rede so nao e mercado. Devolve o preco e nao opina.
        if ($total === 1) {
            return self::resposta('rede_unica', null, $limpos, $total, []);
        }

        $valores = array_column($limpos, 'preco');

        if ($total < self::MINIMO_PARA_MEDIANA) {
            // Duas redes: sem terceiro para desempatar. Ou elas concordam
            // razoavelmente, ou a divergencia e o proprio resultado.
            $razao = max($valores) / min($valores);

            return $razao > self::FATOR_DIVERGENTE
                ? self::resposta('divergente', null, $limpos, $total, [])
                : self::resposta('ok', self::mediana($valores), $limpos, $total, []);
        }

        $mediana = self::mediana($valores);

        $suspeitos = array_values(array_filter(
            $limpos,
            fn ($p) => $p['preco'] > $mediana * self::FATOR_SUSPEITO
                || $p['preco'] < $mediana / self::FATOR_SUSPEITO
        ));

        if (count($suspeitos) === 0) {
            return self::resposta('ok', $mediana, $limpos, $total, []);
        }

        /*
         * Exatamente um fora de linha e o caso tratavel: os outros concordam
         * entre si, entao a maioria arbitra e o intruso sai.
         *
         * Dois ou mais e o caso do Sal Amargo — os precos formam blocos e a
         * mediana caiu entre eles. Aqui nao ha maioria: descartar seria
         * escolher um lado por sorteio. Devolve tudo e deixa a decisao para
         * quem conhece o produto.
         */
        if (count($suspeitos) > 1) {
            return self::resposta('divergente', null, $limpos, $total, []);
        }

        $descartado = $suspeitos[0];
        $restantes = array_values(array_filter(
            $limpos,
            fn ($p) => $p['farmacia_id'] !== $descartado['farmacia_id']
        ));

        return self::resposta(
            'ok',
            self::mediana(array_column($restantes, 'preco')),
            $restantes,
            $total,
            [$descartado]
        );
    }

    /**
     * @param  array<int, array{farmacia_id: int, nome_farmacia: string, preco: float}>  $considerados
     * @param  array<int, array{farmacia_id: int, nome_farmacia: string, preco: float}>  $descartados
     */
    private static function resposta(
        string $estado,
        ?float $mediana,
        array $considerados,
        int $redes,
        array $descartados
    ): array {
        $valores = array_column($considerados, 'preco');

        return [
            'estado'             => $estado,
            'mediana'            => $mediana !== null ? round($mediana, 2) : null,
            'minimo'             => $valores ? round(min($valores), 2) : null,
            'maximo'             => $valores ? round(max($valores), 2) : null,
            'redes'              => $redes,
            'redes_consideradas' => count($considerados),
            'descartados'        => $descartados,
        ];
    }

    /** @param  array<int, float>  $valores */
    private static function mediana(array $valores): float
    {
        sort($valores);
        $n = count($valores);
        $meio = intdiv($n, 2);

        return $n % 2 === 1
            ? $valores[$meio]
            : ($valores[$meio - 1] + $valores[$meio]) / 2;
    }
}
