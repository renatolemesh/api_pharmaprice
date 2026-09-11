<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Relatorio de aumentos e reducoes: cada mudanca de preco comparada com o
 * preco que ela substituiu.
 *
 * Nao ha o que redescobrir: `precos.preco_anterior` ja traz, em cada linha, o
 * valor que vigorava antes dela (ver a migration que criou a coluna). A
 * variacao e uma conta sobre a propria linha, e o relatorio inteiro e um filtro
 * por faixa de `data` — o mesmo recorte que o painel usa, resolvido no
 * `idx_precos_janela_painel`.
 *
 * Mora aqui, e nao no controller, porque a tela e o export precisam da mesma
 * definicao de "quais linhas entram". Se cada um montasse o filtro a seu modo,
 * o arquivo baixado deixaria de bater com a tela.
 */
class VariacaoPreco
{
    /** Periodo padrao quando nenhuma data e informada. */
    public const DIAS_PADRAO = 30;

    /**
     * Teto do periodo.
     *
     * Medido na base de producao: a pagina de 30 dias sai em 32ms e a de um
     * ano em 0,57s. Sem teto, "desde sempre" ordenaria 1,7 milhao de mudancas a
     * cada troca de pagina.
     */
    public const DIAS_MAXIMO = 366;

    /**
     * Faixa fora da qual a variacao e tratada como provavel erro de coleta —
     * os mesmos limites do painel (DashboardController::dadosMaioresMudancas).
     *
     * Na base inteira sao ~11 mil mudancas acima de +500% e ~15 mil com queda
     * maior que 80%. Quase nunca e remarcacao: e o produto trocando de
     * apresentacao com o mesmo EAN, ou o scraper lendo o preco de outro item.
     * Sem esse corte, ordenar por variacao abria o relatorio com FABRAZYME
     * indo de R$ 41,99 para R$ 23.070,60 (+54.843%).
     *
     * Elas nao somem em silencio: saem da lista por padrao, entram na contagem
     * de `suspeitas` do resumo e voltam com `incluir_suspeitas`.
     */
    public const ALTA_MAXIMA = 5.0;    // +500%
    public const QUEDA_MAXIMA = -0.8;  // -80%

    /** Variacao relativa da linha, em fracao (0,1 = +10%). */
    private const VARIACAO = '((p.preco - p.preco_anterior) / p.preco_anterior)';

    /** @param  array<string, string>  $extras  regras do chamador (ex.: `page`) */
    public static function validador(Request $request, array $extras = []): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), $extras + [
            'farmacia'        => 'nullable|string',
            'data-inicio'     => 'nullable|date',
            'data-fim'        => 'nullable|date',
            'tipo'            => 'nullable|in:todos,aumento,reducao',
            'variacao_minima' => 'nullable|numeric|min:0|max:100000',
            'ordem'           => 'nullable|in:variacao,data',
        ])->after(function ($validator) use ($request) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$inicio, $fim] = self::periodo($request->query('data-inicio'), $request->query('data-fim'));

            if ($inicio->gt($fim)) {
                $validator->errors()->add('data-inicio', 'A data inicial é posterior à data final.');
            } elseif ((int) $inicio->diffInDays($fim, true) + 1 > self::DIAS_MAXIMO) {
                $validator->errors()->add('data-fim', 'O período pode ter no máximo ' . self::DIAS_MAXIMO . ' dias.');
            }
        });
    }

    /** Filtros ja com os padroes aplicados. Chamar depois do `validador`. */
    public static function filtros(Request $request): array
    {
        [$inicio, $fim] = self::periodo($request->query('data-inicio'), $request->query('data-fim'));
        $farmacia = $request->query('farmacia');

        return [
            'inicio'            => $inicio->toDateString(),
            'fim'               => $fim->toDateString(),
            'farmacias'         => $farmacia
                ? array_values(array_filter(array_map('intval', preg_split('/[\s+,;]+/', $farmacia))))
                : [],
            'tipo'              => $request->query('tipo') ?: 'todos',
            'variacao_minima'   => (float) $request->query('variacao_minima', 0),
            'ordem'             => $request->query('ordem') ?: 'variacao',
            // Presenca do parametro, como o `incluir_inativos` do relatorio atual.
            'incluir_suspeitas' => $request->query->has('incluir_suspeitas'),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private static function periodo(?string $inicio, ?string $fim): array
    {
        $fim = $fim ? Carbon::parse($fim)->startOfDay() : Carbon::today();
        $inicio = $inicio
            ? Carbon::parse($inicio)->startOfDay()
            : $fim->copy()->subDays(self::DIAS_PADRAO - 1);

        return [$inicio, $fim];
    }

    /**
     * Contagens do recorte, numa passada so pelo indice.
     *
     * Serve de resumo para a tela e de `total` para a paginacao — por isso o
     * controller nao usa `paginate()`, que rodaria um COUNT a mais sobre o
     * mesmo recorte.
     *
     * @return array{aumentos: int, reducoes: int, suspeitas: int}
     */
    public static function resumo(array $f): array
    {
        $dentroDaFaixa = self::VARIACAO . ' BETWEEN ? AND ?';
        $faixa = [self::QUEDA_MAXIMA, self::ALTA_MAXIMA];
        $incluir = $f['incluir_suspeitas'] ? 1 : 0;

        $linha = self::base($f)
            ->selectRaw("
                SUM(CASE WHEN p.preco > p.preco_anterior AND (? OR {$dentroDaFaixa}) THEN 1 ELSE 0 END) AS aumentos,
                SUM(CASE WHEN p.preco < p.preco_anterior AND (? OR {$dentroDaFaixa}) THEN 1 ELSE 0 END) AS reducoes,
                SUM(CASE WHEN " . self::VARIACAO . " > ? THEN 1 ELSE 0 END) AS suspeitas_alta,
                SUM(CASE WHEN " . self::VARIACAO . " < ? THEN 1 ELSE 0 END) AS suspeitas_queda
            ", [$incluir, ...$faixa, $incluir, ...$faixa, self::ALTA_MAXIMA, self::QUEDA_MAXIMA])
            ->first();

        // Suspeitas do lado que a tela esta olhando: pedindo so reducoes, as
        // altas absurdas nao estao "ocultas" — nao foram pedidas.
        $suspeitas = match ($f['tipo']) {
            'aumento' => (int) $linha->suspeitas_alta,
            'reducao' => (int) $linha->suspeitas_queda,
            default   => (int) $linha->suspeitas_alta + (int) $linha->suspeitas_queda,
        };

        return [
            'aumentos'  => (int) $linha->aumentos,
            'reducoes'  => (int) $linha->reducoes,
            'suspeitas' => $suspeitas,
        ];
    }

    /**
     * Uma pagina do relatorio.
     *
     * Ordena e corta so em `precos`, e so depois junta descricao e farmacia das
     * linhas que sobraram. A ordenacao e por uma conta, entao nao ha indice que
     * a entregue pronta: com o join antes, o filesort carregava descricao e
     * nome de cada uma das 47 mil mudancas de 30 dias para devolver 50. Medido
     * na base de producao: 0,64s -> 0,03s em 30 dias, 5,7s -> 0,57s em um ano.
     */
    public static function pagina(array $f, int $pagina, int $porPagina): Collection
    {
        $ids = self::ordenar(self::filtrada($f)->select('p.preco_id'), $f)
            ->forPage($pagina, $porPagina);

        $query = DB::query()
            ->fromSub($ids, 'pagina')
            ->join('precos as p', 'p.preco_id', '=', 'pagina.preco_id');

        return self::ordenar(self::comDetalhes($query), $f)->get();
    }

    /** O recorte inteiro, ordenado, para o export percorrer em cursor. */
    public static function consulta(array $f): Builder
    {
        return self::ordenar(self::comDetalhes(self::filtrada($f)), $f);
    }

    /**
     * Mudancas de verdade dentro do recorte, sem o filtro de tipo nem o de
     * suspeitas — o resumo precisa contar os dois lados.
     */
    private static function base(array $f): Builder
    {
        return DB::table('precos as p')
            // Primeiro preco conhecido do par nao substitui nada, entao nao e
            // mudanca. `> 0` em vez de `IS NOT NULL` porque ha anterior zerado
            // na base, e ali a variacao seria divisao por zero.
            ->where('p.preco_anterior', '>', 0)
            ->whereColumn('p.preco', '<>', 'p.preco_anterior')
            ->whereBetween('p.data', [$f['inicio'], $f['fim']])
            ->when($f['farmacias'], fn ($q) => $q->whereIn('p.farmacia_id', $f['farmacias']))
            ->when($f['variacao_minima'] > 0, fn ($q) => $q->whereRaw(
                'ABS(' . self::VARIACAO . ') * 100 >= ?',
                [$f['variacao_minima']]
            ));
    }

    private static function filtrada(array $f): Builder
    {
        $query = self::base($f);

        if (!$f['incluir_suspeitas']) {
            $query->whereRaw(self::VARIACAO . ' BETWEEN ? AND ?', [self::QUEDA_MAXIMA, self::ALTA_MAXIMA]);
        }

        if ($f['tipo'] === 'aumento') {
            $query->whereColumn('p.preco', '>', 'p.preco_anterior');
        } elseif ($f['tipo'] === 'reducao') {
            $query->whereColumn('p.preco', '<', 'p.preco_anterior');
        }

        return $query;
    }

    /**
     * Maior variacao primeiro — no sentido que foi pedido. Com os dois lados
     * juntos vale o tamanho do movimento, subindo ou descendo. `preco_id`
     * desempata, senao LIMIT/OFFSET sobre empate pode repetir ou pular linha
     * entre paginas.
     */
    private static function ordenar(Builder $query, array $f): Builder
    {
        if ($f['ordem'] === 'data') {
            return $query->orderByDesc('p.data')->orderByDesc('p.preco_id');
        }

        $expressao = match ($f['tipo']) {
            'aumento' => self::VARIACAO . ' DESC',
            'reducao' => self::VARIACAO . ' ASC',
            default   => 'ABS(' . self::VARIACAO . ') DESC',
        };

        return $query->orderByRaw($expressao)->orderByDesc('p.preco_id');
    }

    private static function comDetalhes(Builder $query): Builder
    {
        return $query
            ->join('produtos as prod', 'p.produto_id', '=', 'prod.produto_id')
            ->join('farmacias as f', 'p.farmacia_id', '=', 'f.farmacia_id')
            ->select([
                'p.preco_id',
                'p.produto_id',
                'p.farmacia_id',
                'prod.descricao',
                'prod.laboratorio',
                'prod.EAN',
                'f.nome_farmacia',
                'p.preco_anterior',
                'p.data_anterior',
                'p.preco',
                'p.data',
            ])
            ->selectRaw('ROUND(' . self::VARIACAO . ' * 100, 2) AS variacao');
    }
}
