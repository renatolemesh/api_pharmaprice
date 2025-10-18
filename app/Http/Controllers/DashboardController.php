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
     * Get general statistics for the dashboard with period comparison
     */
    public function getStatistics(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');
        $days = max(1, (int) $request->query('days', 7)); // Default 7 days, minimum 1

        $cacheKey = $farmaciaId
            ? "dashboard_stats_farmacia_{$farmaciaId}_days_{$days}"
            : "dashboard_stats_global_days_{$days}";

        return Cache::remember($cacheKey, 300, function () use ($farmaciaId, $days) {
            $currentPeriodStart = Carbon::now()->subDays($days)->startOfDay();
            $previousPeriodStart = Carbon::now()->subDays($days * 2)->startOfDay();
            $previousPeriodEnd = $currentPeriodStart->copy()->subSecond();

            // Current period analysis
            $currentAnalysis = DB::table('precos as p1')
                ->select([
                    DB::raw('COUNT(DISTINCT p1.produto_id) as updated_products'),
                    DB::raw('AVG(((p1.preco - p2.preco) / NULLIF(p2.preco, 0)) * 100) as average_variation'),
                    DB::raw('SUM(CASE WHEN p1.preco > p2.preco THEN 1 ELSE 0 END) as price_increases'),
                    DB::raw('SUM(CASE WHEN p1.preco < p2.preco THEN 1 ELSE 0 END) as price_decreases'),
                    DB::raw('AVG(DATEDIFF(p1.data, p2.data)) as average_change_time')
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
                ->whereBetween('p1.data', [$currentPeriodStart, Carbon::now()])
                ->first();

            // Previous period analysis
            $previousAnalysis = DB::table('precos as p1')
                ->select([
                    DB::raw('COUNT(DISTINCT p1.produto_id) as updated_products'),
                    DB::raw('AVG(((p1.preco - p2.preco) / NULLIF(p2.preco, 0)) * 100) as average_variation'),
                    DB::raw('SUM(CASE WHEN p1.preco > p2.preco THEN 1 ELSE 0 END) as price_increases'),
                    DB::raw('SUM(CASE WHEN p1.preco < p2.preco THEN 1 ELSE 0 END) as price_decreases'),
                    DB::raw('AVG(DATEDIFF(p1.data, p2.data)) as average_change_time')
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
                ->whereBetween('p1.data', [$previousPeriodStart, $previousPeriodEnd])
                ->first();

            // Calculate percentage changes
            $calculateChange = function($old, $new) {
                if ($old == 0) return $new > 0 ? 100 : 0;
                return round((($new - $old) / abs($old)) * 100, 2);
            };

            // Calculate percentage point difference (for values that are already percentages)
            $calculatePointDifference = function($old, $new) {
                return round($new - $old, 2);
            };

            // Total products (not period-dependent)
            $totalProducts = DB::table('produtos')
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

            // Total prices stored (not period-dependent)
            $totalPrices = DB::table('precos')
                ->when($farmaciaId, fn($q) => $q->where('farmacia_id', $farmaciaId))
                ->count();

            return response()->json(['data' => [
                'period_days' => $days,
                'current_period' => [
                    'start' => $currentPeriodStart->toDateString(),
                    'end' => Carbon::now()->toDateString(),
                ],
                'updated_products' => $currentAnalysis->updated_products ?? 0,
                'updated_products_change' => $calculateChange(
                    $previousAnalysis->updated_products ?? 0,
                    $currentAnalysis->updated_products ?? 0
                ),
                'average_variation' => round($currentAnalysis->average_variation ?? 0, 2),
                'average_variation_change' => $calculatePointDifference(
                    $previousAnalysis->average_variation ?? 0,
                    $currentAnalysis->average_variation ?? 0
                ),
                'price_increases' => $currentAnalysis->price_increases ?? 0,
                'price_increases_change' => $calculateChange(
                    $previousAnalysis->price_increases ?? 0,
                    $currentAnalysis->price_increases ?? 0
                ),
                'price_decreases' => $currentAnalysis->price_decreases ?? 0,
                'price_decreases_change' => $calculateChange(
                    $previousAnalysis->price_decreases ?? 0,
                    $currentAnalysis->price_decreases ?? 0
                ),
                'average_change_time' => round($currentAnalysis->average_change_time ?? 0, 1),
                'average_change_time_change' => $calculateChange(
                    $previousAnalysis->average_change_time ?? 0,
                    $currentAnalysis->average_change_time ?? 0
                ),
                'total_products' => $totalProducts,
                'total_prices_stored' => $totalPrices,
            ]]);
        });
    }


    /**
     * Get price movement trends over time
     */
    public function getPriceTrends(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');
        $days = $request->query('days', 7);

        $cacheKey = $farmaciaId
            ? "price_trends_{$farmaciaId}_{$days}"
            : "price_trends_global_{$days}";

        return Cache::remember($cacheKey, 600, function () use ($farmaciaId, $days) {
            $startDate = Carbon::now()->subDays($days - 1)->toDateString();
            $endDate = Carbon::now()->toDateString();

            // Build bindings array
            $bindings = [$startDate, $endDate, $startDate];
            if ($farmaciaId) {
                $bindings[] = $farmaciaId;
            }

            $query = "
                WITH RECURSIVE date_series AS (
                    SELECT DATE(?) as date
                    UNION ALL
                    SELECT DATE_ADD(date, INTERVAL 1 DAY)
                    FROM date_series
                    WHERE date < DATE(?)
                ),
                price_changes AS (
                    SELECT
                        DATE(p1.data) as date,
                        p1.preco as current_price,
                        p1.produto_id,
                        p1.farmacia_id,
                        LAG(p1.preco) OVER (
                            PARTITION BY p1.produto_id, p1.farmacia_id
                            ORDER BY p1.data
                        ) as previous_price
                    FROM precos p1
                    WHERE p1.data >= ?
                    " . ($farmaciaId ? "AND p1.farmacia_id = ?" : "") . "
                ),
                daily_trends AS (
                    SELECT
                        date,
                        SUM(CASE WHEN current_price > previous_price THEN 1 ELSE 0 END) as increases,
                        SUM(CASE WHEN current_price < previous_price THEN 1 ELSE 0 END) as decreases
                    FROM price_changes
                    WHERE previous_price IS NOT NULL
                    GROUP BY date
                )
                SELECT
                    ds.date,
                    COALESCE(dt.increases, 0) as increases,
                    COALESCE(dt.decreases, 0) as decreases
                FROM date_series ds
                LEFT JOIN daily_trends dt ON ds.date = dt.date
                ORDER BY ds.date;
            ";

            $trends = DB::select($query, $bindings);

            return response()->json(['data' => $trends]);
        });
    }

   /**
     * Get products with the biggest price changes
     */
    public function getTopPriceChanges(Request $request)
    {
        $farmaciaId = $request->query('farmacia_id');
        $limit = $request->query('limit', 10);
        $type = $request->query('type', 'all'); // 'increase', 'decrease', 'all'

        $cacheKey = "top_changes_{$farmaciaId}_{$limit}_{$type}_with_lists";

        return Cache::remember($cacheKey, 300, function () use ($farmaciaId, $limit, $type) {
            $lastWeek = Carbon::now()->subWeek()->toDateString();
            $minValue = 5;

            // Subquery with window function
            $subquery = DB::table('precos as p1')
                ->join('produtos', 'p1.produto_id', '=', 'produtos.produto_id')
                ->join('farmacias', 'p1.farmacia_id', '=', 'farmacias.farmacia_id')
                ->selectRaw("
                    produtos.descricao as product_name,
                    produtos.EAN as ean,
                    farmacias.nome_farmacia as pharmacy_name,
                    p1.preco as current_price,
                    p1.data as change_date,
                    p1.produto_id,
                    p1.farmacia_id,
                    LAG(p1.preco) OVER (
                        PARTITION BY p1.produto_id, p1.farmacia_id
                        ORDER BY p1.data
                    ) as previous_price
                ")
                ->when($farmaciaId, fn($q) => $q->where('p1.farmacia_id', $farmaciaId))
                ->where('p1.data', '>=', $lastWeek);

            $baseQuery = DB::table(DB::raw("({$subquery->toSql()}) as price_data"))
                ->mergeBindings($subquery)
                ->selectRaw("
                    product_name,
                    ean,
                    pharmacy_name,
                    previous_price,
                    current_price,
                    ROUND(((current_price - previous_price) / NULLIF(previous_price, 0) * 100), 2) as variation_percent,
                    change_date
                ")
                ->whereNotNull('previous_price')
                ->where('previous_price', '>', $minValue)
                ->whereRaw('ABS((current_price - previous_price) / previous_price * 100) <= 500');

            // 🟢 Top Increase (only first product)
            $topIncrease = (clone $baseQuery)
                ->whereRaw('current_price > previous_price')
                ->orderByRaw('((current_price - previous_price) / previous_price) DESC')
                ->limit(1)
                ->first();

            // 🔴 Top Decrease (only first product)
            $topDecrease = (clone $baseQuery)
                ->whereRaw('current_price < previous_price')
                ->orderByRaw('((current_price - previous_price) / previous_price) ASC')
                ->limit(1)
                ->first();

            // 🟡 Main query based on type (for existing behavior)
            $mainQuery = clone $baseQuery;

            if ($type === 'increase') {
                $mainQuery->whereRaw('current_price > previous_price')
                    ->orderByRaw('((current_price - previous_price) / previous_price) DESC');
            } elseif ($type === 'decrease') {
                $mainQuery->whereRaw('current_price < previous_price')
                    ->orderByRaw('((current_price - previous_price) / previous_price) ASC');
            } else {
                $mainQuery->orderByRaw('ABS((current_price - previous_price) / previous_price) DESC');
            }

            $mainResults = $mainQuery->limit($limit)->get();

            return response()->json([
                'data' => $mainResults,
                'top_price_increase' => $topIncrease,
                'top_price_decrease' => $topDecrease,
            ]);
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
                    'farmacias.farmacia_id as pharmacy_id',
                    'farmacias.nome_farmacia as pharmacy_name',
                    DB::raw('COUNT(DISTINCT precos.produto_id) as updated_products'),
                ])
                ->groupBy('pharmacy_id', 'pharmacy_name')
                ->orderByDesc('updated_products')
                ->get();

            return response()->json(['data' => $stats]);
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
