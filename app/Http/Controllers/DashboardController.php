<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Painel de analises.
 *
 * Todas as perguntas daqui sao da forma "entre estas duas datas, o que aconteceu
 * com os precos". Ate a introducao de `precos.preco_anterior` cada uma delas
 * redescobria, por consulta, qual era o preco antes de cada mudanca — com
 * subconsulta correlacionada em `getStatistics` e funcao de janela nas outras
 * duas. Medido em producao: 3,6s por periodo em `getStatistics`, que roda dois
 * periodos, mais 0,9s do COUNT(*) global; o endpoint inteiro levava ~25s com o
 * cache frio.
 *
 * Agora a coluna ja traz o valor anterior, entao todas viraram agregacao direta
 * sobre uma faixa de `data`.
 *
 * Os metodos publicos so embrulham em JSON; quem calcula e faz cache sao os
 * `dados*` privados. Isso importa para o `getSummary`: antes ele chamava os
 * proprios endpoints e serializava quatro JsonResponse dentro do proprio cache.
 */
class DashboardController extends Controller
{
    /** Janela corrente: o dado muda a cada coleta. */
    private const TTL_PERIODO = 300;

    /** Serie diaria: so muda quando vira o dia. */
    private const TTL_TENDENCIAS = 600;

    /** Contagens globais da base — sobem devagar e custam caro. */
    private const TTL_TOTAIS = 3600;

    /**
     * Geracao do cache.
     *
     * O FileStore nao tem tags, e o `clearCache` anterior montava chaves que
     * nunca existiram — pedia `dashboard_stats_global` quando a chave real era
     * `dashboard_stats_global_days_7`, entao nunca limpou nada. Versionar a
     * chave resolve sem tag: subir o contador torna a geracao inteira
     * inalcancavel de uma vez, e as chaves velhas expiram sozinhas pelo TTL.
     */
    private function geracao(): int
    {
        return (int) Cache::rememberForever('dashboard_geracao', fn () => 1);
    }

    private function chave(string $nome): string
    {
        return 'dashboard_v' . $this->geracao() . '_' . $nome;
    }

    private function escopo(?int $farmaciaId): string
    {
        return $farmaciaId ? "farmacia_{$farmaciaId}" : 'global';
    }

    // -----------------------------------------------------------------------
    // Endpoints
    // -----------------------------------------------------------------------

    public function getStatistics(Request $request)
    {
        [$farmaciaId, $days] = $this->parametros($request);

        return response()->json(['data' => $this->dadosEstatisticas($farmaciaId, $days)]);
    }

    public function getPriceTrends(Request $request)
    {
        [$farmaciaId, $days] = $this->parametros($request);

        return response()->json(['data' => $this->dadosTendencias($farmaciaId, $days)]);
    }

    public function getTopPriceChanges(Request $request)
    {
        [$farmaciaId] = $this->parametros($request);
        $limit = max(1, min(50, (int) $request->query('limit', 5)));
        $type  = in_array($request->query('type'), ['increase', 'decrease'], true)
            ? $request->query('type')
            : 'all';

        return response()->json($this->dadosMaioresMudancas($farmaciaId, $limit, $type));
    }

    public function getPharmacyStats(Request $request)
    {
        return response()->json(['data' => $this->dadosPorFarmacia()]);
    }

    public function getSummary(Request $request)
    {
        [$farmaciaId, $days] = $this->parametros($request);

        $maiores = $this->dadosMaioresMudancas($farmaciaId, 5, 'all');

        return response()->json([
            'statistics'     => $this->dadosEstatisticas($farmaciaId, $days),
            'trends'         => $this->dadosTendencias($farmaciaId, $days),
            'top_changes'    => $maiores,
            'pharmacy_stats' => $this->dadosPorFarmacia(),
        ]);
    }

    public function clearCache(Request $request)
    {
        Cache::forever('dashboard_geracao', $this->geracao() + 1);

        return response()->json(['message' => 'Cache limpo com sucesso']);
    }

    /** @return array{0: ?int, 1: int} */
    private function parametros(Request $request): array
    {
        $farmaciaId = $request->query('farmacia_id');

        return [
            $farmaciaId !== null ? (int) $farmaciaId : null,
            max(1, min(365, (int) $request->query('days', 7))),
        ];
    }

    // -----------------------------------------------------------------------
    // Dados
    // -----------------------------------------------------------------------

    private function dadosEstatisticas(?int $farmaciaId, int $days): array
    {
        $chave = $this->chave('stats_' . $this->escopo($farmaciaId) . "_days_{$days}");

        return Cache::remember($chave, self::TTL_PERIODO, function () use ($farmaciaId, $days) {
            $inicioAtual    = Carbon::now()->subDays($days)->startOfDay();
            $inicioAnterior = Carbon::now()->subDays($days * 2)->startOfDay();
            $fimAnterior    = $inicioAtual->copy()->subSecond();

            $atual    = $this->agregarPeriodo($farmaciaId, $inicioAtual, Carbon::now());
            $anterior = $this->agregarPeriodo($farmaciaId, $inicioAnterior, $fimAnterior);

            // Variacao relativa entre os dois periodos.
            $variacao = function ($velho, $novo) {
                if ((float) $velho == 0.0) {
                    return $novo > 0 ? 100 : 0;
                }
                return round((($novo - $velho) / abs($velho)) * 100, 2);
            };

            // Para valores que ja sao percentuais, a diferenca e em pontos —
            // percentual de percentual nao quer dizer nada.
            $pontos = fn ($velho, $novo) => round($novo - $velho, 2);

            return [
                'period_days' => $days,
                'current_period' => [
                    'start' => $inicioAtual->toDateString(),
                    'end'   => Carbon::now()->toDateString(),
                ],
                'updated_products' => (int) ($atual->updated_products ?? 0),
                'updated_products_change' => $variacao(
                    $anterior->updated_products ?? 0,
                    $atual->updated_products ?? 0
                ),
                'average_variation' => round($atual->average_variation ?? 0, 2),
                'average_variation_change' => $pontos(
                    $anterior->average_variation ?? 0,
                    $atual->average_variation ?? 0
                ),
                'price_increases' => (int) ($atual->price_increases ?? 0),
                'price_increases_change' => $variacao(
                    $anterior->price_increases ?? 0,
                    $atual->price_increases ?? 0
                ),
                'price_decreases' => (int) ($atual->price_decreases ?? 0),
                'price_decreases_change' => $variacao(
                    $anterior->price_decreases ?? 0,
                    $atual->price_decreases ?? 0
                ),
                'average_change_time' => round($atual->average_change_time ?? 0, 1),
                'average_change_time_change' => $variacao(
                    $anterior->average_change_time ?? 0,
                    $atual->average_change_time ?? 0
                ),
            ] + $this->totais($farmaciaId);
        });
    }

    /**
     * Uma passada por `precos` na faixa de datas.
     *
     * `preco_anterior IS NOT NULL` exclui o primeiro preco conhecido de cada
     * par, que nao representa mudanca de nada — e a mesma linha que o JOIN
     * antigo descartava por nao encontrar par anterior.
     */
    private function agregarPeriodo(?int $farmaciaId, Carbon $inicio, Carbon $fim)
    {
        return DB::table('precos')
            ->selectRaw('
                COUNT(DISTINCT produto_id) as updated_products,
                AVG(((preco - preco_anterior) / NULLIF(preco_anterior, 0)) * 100) as average_variation,
                SUM(CASE WHEN preco > preco_anterior THEN 1 ELSE 0 END) as price_increases,
                SUM(CASE WHEN preco < preco_anterior THEN 1 ELSE 0 END) as price_decreases,
                AVG(DATEDIFF(data, data_anterior)) as average_change_time
            ')
            ->whereNotNull('preco_anterior')
            ->when($farmaciaId, fn ($q) => $q->where('farmacia_id', $farmaciaId))
            ->whereBetween('data', [$inicio, $fim])
            ->first();
    }

    /**
     * Totais da base inteira. Cache proprio e mais longo porque nao dependem do
     * periodo pedido: sem isso, cada valor de `days` pagava de novo um COUNT(*)
     * de 0,9s sobre 1,86 milhao de linhas.
     */
    private function totais(?int $farmaciaId): array
    {
        $chave = $this->chave('totais_' . $this->escopo($farmaciaId));

        return Cache::remember($chave, self::TTL_TOTAIS, function () use ($farmaciaId) {
            $produtos = DB::table('produtos')
                ->when($farmaciaId, function ($q) use ($farmaciaId) {
                    return $q->whereExists(function ($sub) use ($farmaciaId) {
                        $sub->select(DB::raw(1))
                            ->from('precos')
                            ->whereColumn('precos.produto_id', 'produtos.produto_id')
                            ->where('precos.farmacia_id', $farmaciaId)
                            ->limit(1);
                    });
                })
                ->count();

            $precos = DB::table('precos')
                ->when($farmaciaId, fn ($q) => $q->where('farmacia_id', $farmaciaId))
                ->count();

            return [
                'total_products'      => $produtos,
                'total_prices_stored' => $precos,
            ];
        });
    }

    /**
     * Aumentos e reducoes por dia, com os dias sem movimento zerados.
     *
     * A versao anterior calculava o preco anterior com LAG sobre uma janela que
     * comecava na propria data inicial. Isso descartava a primeira mudanca de
     * cada produto dentro do periodo, porque para ela o LAG nao tinha de onde
     * olhar para tras — os dias iniciais da serie saiam sistematicamente
     * subcontados. Lendo a coluna, toda mudanca da faixa entra.
     */
    private function dadosTendencias(?int $farmaciaId, int $days): array
    {
        $chave = $this->chave('trends_' . $this->escopo($farmaciaId) . "_days_{$days}");

        return Cache::remember($chave, self::TTL_TENDENCIAS, function () use ($farmaciaId, $days) {
            $inicio = Carbon::now()->subDays($days - 1)->toDateString();
            $fim    = Carbon::now()->toDateString();

            $movimento = DB::table('precos')
                ->selectRaw('
                    data as date,
                    SUM(CASE WHEN preco > preco_anterior THEN 1 ELSE 0 END) as increases,
                    SUM(CASE WHEN preco < preco_anterior THEN 1 ELSE 0 END) as decreases
                ')
                ->whereNotNull('preco_anterior')
                ->when($farmaciaId, fn ($q) => $q->where('farmacia_id', $farmaciaId))
                ->whereBetween('data', [$inicio, $fim])
                ->groupBy('data')
                ->get()
                ->keyBy('date');

            // A serie de datas e montada em PHP, e nao com o CTE RECURSIVE que
            // estava aqui: o banco nao precisa gerar sete linhas para o
            // aplicativo saber quais dias existem entre duas datas.
            $serie = [];
            for ($dia = Carbon::parse($inicio); $dia->lte(Carbon::parse($fim)); $dia->addDay()) {
                $data = $dia->toDateString();
                $linha = $movimento->get($data);

                $serie[] = [
                    'date'      => $data,
                    'increases' => (int) ($linha->increases ?? 0),
                    'decreases' => (int) ($linha->decreases ?? 0),
                ];
            }

            return $serie;
        });
    }

    /**
     * Produtos que mais mexeram de preco na ultima semana.
     *
     * Os filtros de sanidade continuam os mesmos: preco anterior acima de R$ 5
     * (variacao percentual de centavo nao diz nada) e movimento de ate 500%
     * (acima disso e quase sempre erro de coleta, nao promocao).
     */
    private function dadosMaioresMudancas(?int $farmaciaId, int $limit, string $type): array
    {
        $chave = $this->chave(
            'top_' . $this->escopo($farmaciaId) . "_{$limit}_{$type}"
        );

        return Cache::remember($chave, self::TTL_PERIODO, function () use ($farmaciaId, $limit, $type) {
            $base = fn () => DB::table('precos as p')
                ->join('produtos as prod', 'p.produto_id', '=', 'prod.produto_id')
                ->join('farmacias as f', 'p.farmacia_id', '=', 'f.farmacia_id')
                ->selectRaw("
                    prod.descricao as product_name,
                    prod.EAN as ean,
                    f.nome_farmacia as pharmacy_name,
                    p.preco_anterior as previous_price,
                    p.preco as current_price,
                    ROUND(((p.preco - p.preco_anterior) / NULLIF(p.preco_anterior, 0) * 100), 2) as variation_percent,
                    p.data as change_date
                ")
                ->whereNotNull('p.preco_anterior')
                ->where('p.preco_anterior', '>', 5)
                ->whereRaw('ABS((p.preco - p.preco_anterior) / p.preco_anterior * 100) <= 500')
                ->when($farmaciaId, fn ($q) => $q->where('p.farmacia_id', $farmaciaId))
                ->where('p.data', '>=', Carbon::now()->subWeek()->toDateString());

            $altas = $base()
                ->whereColumn('p.preco', '>', 'p.preco_anterior')
                ->orderByRaw('(p.preco - p.preco_anterior) / p.preco_anterior DESC')
                ->limit(5)
                ->get();

            // Queda acima de 80% quase nunca e promocao: e o produto trocando
            // de apresentacao (caixa de 30 virando caixa de 1) com o mesmo EAN.
            $baixas = $base()
                ->whereColumn('p.preco', '<', 'p.preco_anterior')
                ->whereRaw('(p.preco_anterior - p.preco) / p.preco_anterior * 100 <= 80')
                ->orderByRaw('(p.preco - p.preco_anterior) / p.preco_anterior ASC')
                ->limit(5)
                ->get();

            $principal = $base();

            if ($type === 'increase') {
                $principal->whereColumn('p.preco', '>', 'p.preco_anterior')
                    ->orderByRaw('(p.preco - p.preco_anterior) / p.preco_anterior DESC');
            } elseif ($type === 'decrease') {
                $principal->whereColumn('p.preco', '<', 'p.preco_anterior')
                    ->whereRaw('(p.preco_anterior - p.preco) / p.preco_anterior * 100 <= 80')
                    ->orderByRaw('(p.preco - p.preco_anterior) / p.preco_anterior ASC');
            } else {
                $principal->orderByRaw('ABS((p.preco - p.preco_anterior) / p.preco_anterior) DESC');
            }

            return [
                'data'                => $principal->limit($limit)->get()->all(),
                'top_prices_increase' => $altas->all(),
                'top_prices_decrease' => $baixas->all(),
            ];
        });
    }

    private function dadosPorFarmacia(): array
    {
        return Cache::remember($this->chave('por_farmacia'), self::TTL_TENDENCIAS, function () {
            return DB::table('farmacias as f')
                ->leftJoin('precos as p', function ($join) {
                    $join->on('f.farmacia_id', '=', 'p.farmacia_id')
                        ->where('p.data', '>=', Carbon::now()->subWeek()->toDateString());
                })
                ->select([
                    'f.farmacia_id as pharmacy_id',
                    'f.nome_farmacia as pharmacy_name',
                    DB::raw('COUNT(DISTINCT p.produto_id) as updated_products'),
                ])
                ->groupBy('pharmacy_id', 'pharmacy_name')
                ->orderByDesc('updated_products')
                ->get()
                ->all();
        });
    }
}
