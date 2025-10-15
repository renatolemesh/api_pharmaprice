<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Preco;
use App\Models\Produto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get general statistics for the dashboard
     */
    public function getStatistics(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');

        $cacheKey = $farmaciaId
            ? "dashboard_stats_farmacia_{$farmaciaId}"
            : "dashboard_stats_global";

        return Cache::remember($cacheKey, 300, function () use ($farmaciaId) {
            $lastWeek = Carbon::now()->subWeek()->toDateString();

            // Create a CTE with latest previous prices
            $latestPricesSubquery = DB::table('precos as p_inner')
                ->select('p_inner.produto_id', 'p_inner.farmacia_id', DB::raw('MAX(p_inner.data) as max_data'))
                ->when($farmaciaId, fn($q) => $q->where('p_inner.farmacia_id', $farmaciaId))
                ->where('p_inner.data', '<', DB::raw('p_outer.data'))
                ->whereColumn('p_inner.produto_id', 'p_outer.produto_id')
                ->whereColumn('p_inner.farmacia_id', 'p_outer.farmacia_id')
                ->groupBy('p_inner.produto_id', 'p_inner.farmacia_id')
                ->limit(1);

            // Single query for price movements and variations
            $priceAnalysis = DB::table('precos as p1')
                ->select([
                    DB::raw('COUNT(DISTINCT p1.produto_id) as produtos_atualizados'),
                    DB::raw('AVG(((p1.preco - p2.preco) / NULLIF(p2.preco, 0)) * 100) as variacao_media'),
                    DB::raw('SUM(CASE WHEN p1.preco > p2.preco THEN 1 ELSE 0 END) as aumentos'),
                    DB::raw('SUM(CASE WHEN p1.preco < p2.preco THEN 1 ELSE 0 END) as reducoes'),
                    DB::raw('AVG(DATEDIFF(p1.data, p2.data)) as tempo_medio')
                ])
                ->join('precos as p2', function($join) {
                    $join->on('p1.produto_id', '=', 'p2.produto_id')
                        ->on('p1.farmacia_id', '=', 'p2.farmacia_id')
                        ->on('p2.data', '=', DB::raw('(
                            SELECT MAX(p3.data)
                            FROM precos p3
                            WHERE p3.produto_id = p1.produto_id
                            AND p3.farmacia_id = p1.farmacia_id
                            AND p3.data < p1.data
                        )'));
                })
                ->when($farmaciaId, fn($q) => $q->where('p1.farmacia_id', $farmaciaId))
                ->where('p1.data', '>=', $lastWeek)
                ->first();

            // Separate simpler queries
            $totalProdutos = DB::table('produtos')
                ->when($farmaciaId, function($q) use ($farmaciaId) {
                    return $q->whereExists(function($query) use ($farmaciaId) {
                        $query->select(DB::raw(1))
                            ->from('precos')
                            ->whereColumn('precos.produto_id', 'produtos.produto_id')
                            ->where('precos.farmacia_id', $farmaciaId)
                            ->limit(1);
                    });
                })
                ->count();

            $totalPrecos = DB::table('precos')
                ->when($farmaciaId, fn($q) => $q->where('farmacia_id', $farmaciaId))
                ->count();

            return response()->json([
                'produtos_atualizados' => $priceAnalysis->produtos_atualizados ?? 0,
                'variacao_media' => round($priceAnalysis->variacao_media ?? 0, 2),
                'total_produtos' => $totalProdutos,
                'aumentos_preco' => $priceAnalysis->aumentos ?? 0,
                'reducoes_preco' => $priceAnalysis->reducoes ?? 0,
                'tempo_medio_alteracao' => round($priceAnalysis->tempo_medio ?? 0, 1),
                'total_precos_armazenados' => $totalPrecos,
            ]);
        });
    }

    /**
     * Get price movement trends over time
     */
    public function getPriceTrends(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');
        $days = $request->query('days', 30);

        $cacheKey = $farmaciaId
            ? "price_trends_{$farmaciaId}_{$days}"
            : "price_trends_global_{$days}";

        return Cache::remember($cacheKey, 600, function () use ($farmaciaId, $days) {
            $startDate = Carbon::now()->subDays($days)->toDateString();

            // Use window functions for better performance (MySQL 8.0+)
            $trends = DB::table(DB::raw('(
                SELECT
                    DATE(p1.data) as data,
                    p1.preco as preco_atual,
                    p1.produto_id,
                    p1.farmacia_id,
                    LAG(p1.preco) OVER (
                        PARTITION BY p1.produto_id, p1.farmacia_id
                        ORDER BY p1.data
                    ) as preco_anterior
                FROM precos p1
                WHERE p1.data >= ?
                ' . ($farmaciaId ? 'AND p1.farmacia_id = ?' : '') . '
            ) as price_changes'))
            ->selectRaw('
                data,
                SUM(CASE WHEN preco_atual > preco_anterior THEN 1 ELSE 0 END) as aumentos,
                SUM(CASE WHEN preco_atual < preco_anterior THEN 1 ELSE 0 END) as reducoes
            ')
            ->setBindings($farmaciaId ? [$startDate, $farmaciaId] : [$startDate])
            ->whereNotNull('preco_anterior')
            ->groupBy('data')
            ->orderBy('data')
            ->get();

            return response()->json($trends);
        });
    }

    /**
     * Get products with biggest price changes
     */
    public function getTopPriceChanges(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');
        $limit = $request->query('limit', 10);
        $type = $request->query('type', 'all'); // 'increase', 'decrease', 'all'

        $cacheKey = "top_changes_{$farmaciaId}_{$limit}_{$type}";


        return Cache::remember($cacheKey, 300, function () use ($farmaciaId, $limit, $type) {
            $lastWeek = Carbon::now()->subWeek()->toDateString();
            $minValue = 5;
            // Using window functions for better performance (MySQL 8.0+)
            $subquery = DB::table('precos as p1')
                ->join('produtos', 'p1.produto_id', '=', 'produtos.produto_id')
                ->join('farmacias', 'p1.farmacia_id', '=', 'farmacias.farmacia_id')
                ->selectRaw("
                    produtos.descricao,
                    produtos.EAN,
                    farmacias.nome_farmacia,
                    p1.preco as preco_atual,
                    p1.data as data_alteracao,
                    p1.produto_id,
                    p1.farmacia_id,
                    LAG(p1.preco) OVER (
                        PARTITION BY p1.produto_id, p1.farmacia_id
                        ORDER BY p1.data
                    ) as preco_anterior
                ")
                ->when($farmaciaId, fn($q) => $q->where('p1.farmacia_id', $farmaciaId))
                ->where('p1.data', '>=', $lastWeek);

            $query = DB::table(DB::raw("({$subquery->toSql()}) as price_data"))
                ->mergeBindings($subquery)
                ->selectRaw("
                    descricao,
                    EAN,
                    nome_farmacia,
                    preco_anterior,
                    preco_atual,
                    ((preco_atual - preco_anterior) / NULLIF(preco_anterior, 0) * 100) as variacao_percentual,
                    data_alteracao
                ")
                ->whereNotNull('preco_anterior')
                ->where('preco_anterior', '>', $minValue)
                // Filter out changes greater than 500% (likely errors)
                ->whereRaw('ABS((preco_atual - preco_anterior) / preco_anterior * 100) <= 500');

            if ($type === 'increase') {
                $query->whereRaw('preco_atual > preco_anterior')
                    ->orderByRaw('((preco_atual - preco_anterior) / preco_anterior) DESC');
            } elseif ($type === 'decrease') {
                $query->whereRaw('preco_atual < preco_anterior')
                    ->orderByRaw('((preco_atual - preco_anterior) / preco_anterior) ASC');
            } else {
                $query->orderByRaw('ABS((preco_atual - preco_anterior) / preco_anterior) DESC');
            }

            $results = $query->limit($limit)->get();

            return response()->json($results);
        });
    }



    /**
     * Get pharmacy update statistics
     */
    public function getPharmacyStats(Request $request)
    {
        $cacheKey = "pharmacy_stats";

        return Cache::remember($cacheKey, 600, function () {
            $lastWeek = Carbon::now()->subWeek()->toDateString();

            $stats = DB::table('farmacias')
                ->leftJoin('precos', function($join) use ($lastWeek) {
                    $join->on('farmacias.farmacia_id', '=', 'precos.farmacia_id')
                        ->where('precos.data', '>=', $lastWeek);
                })
                ->select([
                    'farmacias.farmacia_id',
                    'farmacias.nome_farmacia',
                    DB::raw('COUNT(DISTINCT precos.produto_id) as produtos_atualizados')
                ])
                ->groupBy('farmacias.farmacia_id', 'farmacias.nome_farmacia')
                ->orderByDesc('produtos_atualizados')
                ->get();

            return response()->json($stats);
        });
    }

    /**
     * Get a complete dashboard summary (optional - for single request)
     */
    public function getSummary(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');

        $cacheKey = $farmaciaId
            ? "dashboard_summary_{$farmaciaId}"
            : "dashboard_summary_global";

        return Cache::remember($cacheKey, 300, function () use ($request) {
            // Chama os métodos individuais
            $statistics = $this->getStatistics($request)->getData();
            $topChanges = $this->getTopPriceChanges($request)->getData();

            return response()->json([
                'statistics' => $statistics,
                'top_changes' => $topChanges,
            ]);
        });
    }

    /**
     * Clear dashboard cache
     */
    public function clearCache(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');

        $patterns = [
            "dashboard_stats_*",
            "price_trends_*",
            "top_changes_*",
            "pharmacy_stats",
        ];

        foreach ($patterns as $pattern) {
            if ($farmaciaId) {
                Cache::forget(str_replace('*', "farmacia_{$farmaciaId}", $pattern));
                Cache::forget(str_replace('*', "{$farmaciaId}_*", $pattern));
            } else {
                Cache::forget(str_replace('*', 'global', $pattern));
            }
        }

        return response()->json(['message' => 'Cache limpo com sucesso']);
    }
}
