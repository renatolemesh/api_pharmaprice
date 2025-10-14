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
            $baseQuery = DB::table('precos')
                ->join('produtos', 'precos.produto_id', '=', 'produtos.produto_id');

            if ($farmaciaId) {
                $baseQuery->where('precos.farmacia_id', $farmaciaId);
            }

            // Data de uma semana atrás
            $lastWeek = Carbon::now()->subWeek();

            // Produtos atualizados na última semana
            $produtosAtualizados = (clone $baseQuery)
                ->where('precos.data', '>=', $lastWeek)
                ->distinct('precos.produto_id')
                ->count('precos.produto_id');

            // Variação média de preços
            $variacaoMedia = DB::table('precos as p1')
                ->join('precos as p2', function($join) {
                    $join->on('p1.produto_id', '=', 'p2.produto_id')
                         ->on('p1.farmacia_id', '=', 'p2.farmacia_id')
                         ->whereRaw('p1.data > p2.data');
                })
                ->when($farmaciaId, function($q) use ($farmaciaId) {
                    return $q->where('p1.farmacia_id', $farmaciaId);
                })
                ->where('p1.data', '>=', $lastWeek)
                ->whereRaw('p2.data = (
                    SELECT MAX(data)
                    FROM precos
                    WHERE produto_id = p1.produto_id
                    AND farmacia_id = p1.farmacia_id
                    AND data < p1.data
                )')
                ->selectRaw('AVG(((p1.preco - p2.preco) / p2.preco) * 100) as variacao')
                ->value('variacao') ?? 0;

            // Total de produtos
            $totalProdutos = DB::table('produtos')
                ->when($farmaciaId, function($q) use ($farmaciaId) {
                    return $q->whereExists(function($query) use ($farmaciaId) {
                        $query->select(DB::raw(1))
                              ->from('precos')
                              ->whereColumn('precos.produto_id', 'produtos.produto_id')
                              ->where('precos.farmacia_id', $farmaciaId);
                    });
                })
                ->count();

            // Aumentos e reduções de preço
            $movimentos = DB::table('precos as p1')
                ->join('precos as p2', function($join) {
                    $join->on('p1.produto_id', '=', 'p2.produto_id')
                         ->on('p1.farmacia_id', '=', 'p2.farmacia_id');
                })
                ->when($farmaciaId, function($q) use ($farmaciaId) {
                    return $q->where('p1.farmacia_id', $farmaciaId);
                })
                ->where('p1.data', '>=', $lastWeek)
                ->whereRaw('p2.data = (
                    SELECT MAX(data)
                    FROM precos
                    WHERE produto_id = p1.produto_id
                    AND farmacia_id = p1.farmacia_id
                    AND data < p1.data
                )')
                ->selectRaw('
                    SUM(CASE WHEN p1.preco > p2.preco THEN 1 ELSE 0 END) as aumentos,
                    SUM(CASE WHEN p1.preco < p2.preco THEN 1 ELSE 0 END) as reducoes
                ')
                ->first();

            // Tempo médio para alteração
            $tempoMedio = DB::table('precos as p1')
                ->join('precos as p2', function($join) {
                    $join->on('p1.produto_id', '=', 'p2.produto_id')
                         ->on('p1.farmacia_id', '=', 'p2.farmacia_id');
                })
                ->when($farmaciaId, function($q) use ($farmaciaId) {
                    return $q->where('p1.farmacia_id', $farmaciaId);
                })
                ->whereRaw('p2.data = (
                    SELECT MAX(data)
                    FROM precos
                    WHERE produto_id = p1.produto_id
                    AND farmacia_id = p1.farmacia_id
                    AND data < p1.data
                )')
                ->selectRaw('AVG(DATEDIFF(p1.data, p2.data)) as media_dias')
                ->value('media_dias') ?? 0;

            // Total de preços armazenados
            $totalPrecos = DB::table('precos')
                ->when($farmaciaId, function($q) use ($farmaciaId) {
                    return $q->where('farmacia_id', $farmaciaId);
                })
                ->count();

            return response()->json([
                'produtos_atualizados' => $produtosAtualizados,
                'variacao_media' => round($variacaoMedia, 2),
                'total_produtos' => $totalProdutos,
                'aumentos_preco' => $movimentos->aumentos ?? 0,
                'reducoes_preco' => $movimentos->reducoes ?? 0,
                'tempo_medio_alteracao' => round($tempoMedio, 1),
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
        $days = $request->query('days', 30); // Padrão 30 dias

        $cacheKey = $farmaciaId
            ? "price_trends_{$farmaciaId}_{$days}"
            : "price_trends_global_{$days}";

        return Cache::remember($cacheKey, 600, function () use ($farmaciaId, $days) {
            $startDate = Carbon::now()->subDays($days);

            $trends = DB::table('precos as p1')
                ->join('precos as p2', function($join) {
                    $join->on('p1.produto_id', '=', 'p2.produto_id')
                         ->on('p1.farmacia_id', '=', 'p2.farmacia_id');
                })
                ->when($farmaciaId, function($q) use ($farmaciaId) {
                    return $q->where('p1.farmacia_id', $farmaciaId);
                })
                ->where('p1.data', '>=', $startDate)
                ->whereRaw('p2.data = (
                    SELECT MAX(data)
                    FROM precos
                    WHERE produto_id = p1.produto_id
                    AND farmacia_id = p1.farmacia_id
                    AND data < p1.data
                )')
                ->selectRaw("
                    DATE(p1.data) as data,
                    SUM(CASE WHEN p1.preco > p2.preco THEN 1 ELSE 0 END) as aumentos,
                    SUM(CASE WHEN p1.preco < p2.preco THEN 1 ELSE 0 END) as reducoes
                ")
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
            $query = DB::table('precos as p1')
                ->join('precos as p2', function($join) {
                    $join->on('p1.produto_id', '=', 'p2.produto_id')
                         ->on('p1.farmacia_id', '=', 'p2.farmacia_id');
                })
                ->join('produtos', 'p1.produto_id', '=', 'produtos.produto_id')
                ->join('farmacias', 'p1.farmacia_id', '=', 'farmacias.farmacia_id')
                ->when($farmaciaId, function($q) use ($farmaciaId) {
                    return $q->where('p1.farmacia_id', $farmaciaId);
                })
                ->where('p1.data', '>=', Carbon::now()->subWeek())
                ->whereRaw('p2.data = (
                    SELECT MAX(data)
                    FROM precos
                    WHERE produto_id = p1.produto_id
                    AND farmacia_id = p1.farmacia_id
                    AND data < p1.data
                )')
                ->selectRaw("
                    produtos.descricao,
                    produtos.EAN,
                    farmacias.nome_farmacia,
                    p2.preco as preco_anterior,
                    p1.preco as preco_atual,
                    ((p1.preco - p2.preco) / p2.preco * 100) as variacao_percentual,
                    p1.data as data_alteracao
                ");

            if ($type === 'increase') {
                $query->whereRaw('p1.preco > p2.preco')
                      ->orderByRaw('((p1.preco - p2.preco) / p2.preco) DESC');
            } elseif ($type === 'decrease') {
                $query->whereRaw('p1.preco < p2.preco')
                      ->orderByRaw('((p1.preco - p2.preco) / p2.preco) ASC');
            } else {
                $query->orderByRaw('ABS((p1.preco - p2.preco) / p2.preco) DESC');
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
            $stats = DB::table('farmacias')
                ->leftJoin('precos', function($join) {
                    $join->on('farmacias.farmacia_id', '=', 'precos.farmacia_id')
                         ->where('precos.data', '>=', Carbon::now()->subWeek());
                })
                ->selectRaw("
                    farmacias.farmacia_id,
                    farmacias.nome_farmacia,
                    COUNT(DISTINCT precos.produto_id) as produtos_atualizados
                ")
                ->groupBy('farmacias.farmacia_id', 'farmacias.nome_farmacia')
                ->orderBy('produtos_atualizados', 'desc')
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
