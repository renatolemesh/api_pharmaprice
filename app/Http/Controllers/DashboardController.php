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

            // Single query for price movements and variations
            $priceAnalysis = DB::table('precos as p1')
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
                ->where('p1.data', '>=', $lastWeek)
                ->first();

            // Total products
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

            // Total prices
            $totalPrices = DB::table('precos')
                ->when($farmaciaId, fn($q) => $q->where('farmacia_id', $farmaciaId))
                ->count();

            return response()->json([
                'updated_products' => $priceAnalysis->updated_products ?? 0,
                'average_variation' => round($priceAnalysis->average_variation ?? 0, 2),
                'total_products' => $totalProducts,
                'price_increases' => $priceAnalysis->price_increases ?? 0,
                'price_decreases' => $priceAnalysis->price_decreases ?? 0,
                'average_change_time' => round($priceAnalysis->average_change_time ?? 0, 1),
                'total_prices_stored' => $totalPrices,
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
                    DATE(p1.data) as date,
                    p1.preco as currentPrice,
                    p1.produto_id,
                    p1.farmacia_id,
                    LAG(p1.preco) OVER (
                        PARTITION BY p1.produto_id, p1.farmacia_id
                        ORDER BY p1.data
                    ) as oldPrice
                FROM precos p1
                WHERE p1.data >= ?
                ' . ($farmaciaId ? 'AND p1.farmacia_id = ?' : '') . '
            ) as price_changes'))
            ->selectRaw('
                date,
                SUM(CASE WHEN currentPrice > oldPrice THEN 1 ELSE 0 END) as increases,
                SUM(CASE WHEN currentPrice < oldPrice THEN 1 ELSE 0 END) as decreases
            ')
            ->setBindings($farmaciaId ? [$startDate, $farmaciaId] : [$startDate])
            ->whereNotNull('oldPrice')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

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

        $cacheKey = "top_changes_{$farmaciaId}_{$limit}_{$type}";

        return Cache::remember($cacheKey, 300, function () use ($farmaciaId, $limit, $type) {
            $lastWeek = Carbon::now()->subWeek()->toDateString();
            $minValue = 5;

            // Using window functions for better performance (MySQL 8.0+)
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

            $query = DB::table(DB::raw("({$subquery->toSql()}) as price_data"))
                ->mergeBindings($subquery)
                ->selectRaw("
                    product_name,
                    ean,
                    pharmacy_name,
                    previous_price,
                    current_price,
                    ((current_price - previous_price) / NULLIF(previous_price, 0) * 100) as variation_percent,
                    change_date
                ")
                ->whereNotNull('previous_price')
                ->where('previous_price', '>', $minValue)
                // Filter out outliers (changes > 500%)
                ->whereRaw('ABS((current_price - previous_price) / previous_price * 100) <= 500');

            if ($type === 'increase') {
                $query->whereRaw('current_price > previous_price')
                    ->orderByRaw('((current_price - previous_price) / previous_price) DESC');
            } elseif ($type === 'decrease') {
                $query->whereRaw('current_price < previous_price')
                    ->orderByRaw('((current_price - previous_price) / previous_price) ASC');
            } else {
                $query->orderByRaw('ABS((current_price - previous_price) / previous_price) DESC');
            }

            $results = $query->limit($limit)->get();

            return response()->json(['data' => $results]);
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
                    DB::raw('COUNT(DISTINCT precos.produto_id) as updated_products'),
                ])
                ->groupBy('farmacias.farmacia_id', 'farmacias.nome_farmacia')
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
