<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Preco;
use App\Support\PrecoAtual;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PrecoController extends Controller
{
    public function consultar(Request $request) {
        $validator = Validator::make($request->all(), [
            'ean' => 'nullable|string|max:15',
            'descricao' => 'nullable|string|max:255',
            'farmacia' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $ean = $request->query('ean');
        $descricao = $request->query('descricao');
        $farmacia = $request->query('farmacia');
        $noPaginate = $request->query->has('no_paginate');
        $incluirInativos = $request->query->has('incluir_inativos');
        $perPage = $request->query('per_page', 100);

        // `precos_atuais` no lugar da `latest_precos_view`: a view refazia o
        // GROUP BY sobre 1,86 milhao de linhas a cada requisicao, e o
        // paginate() abaixo cobrava isso duas vezes (COUNT + SELECT). Medido
        // nesta base: 4,9s + 4,9s viraram 0,47s + 0,08s.
        $query = DB::table('precos_atuais as p')
            ->join('produtos as prod', 'p.produto_id', '=', 'prod.produto_id')
            ->join('farmacias as f', 'p.farmacia_id', '=', 'f.farmacia_id')
            ->leftJoin('informacoes_produtos as ip', function ($join) {
                $join->on('prod.produto_id', '=', 'ip.produto_id')
                    ->on('p.farmacia_id', '=', 'ip.farmacia_id');
            })
            ->select([
                'prod.descricao',
                'prod.EAN',
                'prod.laboratorio',
                'f.nome_farmacia',
                // Dominio da farmacia. `ip.link` guarda caminho, nao URL, e sem
                // isto quem consome tem que adivinhar o dominio por nome de
                // farmacia - foi assim que o front passou a mandar o link do
                // Unipreco para farmaciasunipreco.com.br depois que a coleta
                // migrou para o marketplace.
                'f.url_base',
                'p.preco',
                'p.data',
                'prod.produto_id',
                'ip.link',
                'ip.ultima_coleta_em',
                'ip.ativo'
            ]);

        // Produtos que nenhuma coleta encontra ha mais de 30 dias saem da
        // consulta: o preco guardado deles nao vale mais nada. O historico
        // continua em `precos` - nada e apagado, so deixa de ser respondido.
        // Linhas sem informacoes_produtos sao mantidas: nao ha o que avaliar.
        if (!$incluirInativos) {
            $query->where(function ($q) {
                $q->where('ip.ativo', 1)->orWhereNull('ip.informacao_id');
            });
        }

        // Apply filters
        if ($ean) {
            $query->where('prod.EAN', $ean);
        } elseif ($descricao) {
            $query->where('prod.descricao', 'like', '%' . $descricao . '%');
        } elseif ($farmacia) {
            $farmaciaIds = array_map('intval', preg_split('/[\s+,;]+/', $farmacia));
            $query->whereIn('f.farmacia_id', $farmaciaIds);
        }

        $query->orderBy('p.preco', 'asc');

        try {
            if ($noPaginate) {
                // Stream results for reports - more memory efficient
                return response()->stream(function () use ($query) {
                    echo '{"data":[';
                    $first = true;

                    $query->chunk(1000, function ($items) use (&$first) {
                        foreach ($items as $item) {
                            if (!$first) echo ',';
                            echo json_encode($item);
                            $first = false;
                        }
                    });

                    echo ']}';
                }, 200, [
                    'Content-Type' => 'application/json',
                    'X-Accel-Buffering' => 'no' // Disable nginx buffering
                ]);
            }

            $resultados = $query->paginate($perPage);

            return response()->json([
                'data' => $resultados->items(),
                'current_page' => $resultados->currentPage(),
                'last_page' => $resultados->lastPage(),
                'per_page' => $resultados->perPage(),
                'total' => $resultados->total()
            ]);
        } catch (\Exception $e) {
            Log::error('Erro na execução da consulta', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Erro interno do servidor'], 500);
        }
    }


    // Método para inserir preço
    public function store(Request $request)
    {
        // Validação dos dados de entrada
        $validatedData = $request->validate([
            'precos' => 'required|array',
            'precos.*.farmacia_id' => 'required|integer',
            'precos.*.produto_id' => 'required|numeric',
            'precos.*.preco' => 'required|numeric',
            'precos.*.data' => 'required|date',
        ]);

        // Remover duplicatas
        $validatedData['precos'] = array_values(array_unique($validatedData['precos'], SORT_REGULAR));

        $resultados = [];
        $conflitos = [];

        // Agrupar por farmacia_id e produto_id
        $novosPrecos = [];
        foreach ($validatedData['precos'] as $dados) {
            $key = $dados['farmacia_id'] . '-' . $dados['produto_id'];
            if (!isset($novosPrecos[$key])) {
                $novosPrecos[$key] = $dados; // Substitui se já existir
            }
        }

        // Precos atuais vindos da projecao. A consulta anterior tinha um
        // subquery com GROUP BY sobre `precos` inteira — o mesmo custo da view,
        // no caminho da escrita. E agrupava por MAX(data), que empata quando
        // dois precos do mesmo dia existem; a projecao usa MAX(preco_id), que
        // nao empata.
        $existingPrices = DB::table('precos_atuais')
            ->whereIn('farmacia_id', array_column($novosPrecos, 'farmacia_id'))
            ->whereIn('produto_id', array_column($novosPrecos, 'produto_id'))
            ->get()
            ->keyBy(fn ($item) => $item->farmacia_id . '-' . $item->produto_id);

        // Validar e preparar os novos preços
        $finalPrecos = [];
        foreach ($novosPrecos as $key => $dados) {
            if (isset($existingPrices[$key])) {
                $precoExistente = $existingPrices[$key];
                if (abs($precoExistente->preco - $dados['preco']) <= 0.01) {
                    // Adicionar aos conflitos se o preço for muito semelhante
                    $conflitos[] = [
                        'produto_id' => $dados['produto_id'],
                        'message' => 'Preço semelhante já existe',
                    ];
                    continue;
                }
            }
            // Adicionar aos preços finais se for válido
            $finalPrecos[] = $dados;
        }

        // Inserir novos preços se houver algum válido
        if (!empty($finalPrecos)) {
            try {
                // Transação para garantir atomicidade
                DB::transaction(function () use ($finalPrecos) {
                    Preco::insert($finalPrecos);
                    // A projeção entra na mesma transação: `precos_atuais` fora
                    // de sincronia faria a busca responder preço inexistente.
                    PrecoAtual::projetar($finalPrecos);
                });
                $resultados = $finalPrecos;

                // Limpa o cache para os preços que foram inseridos
                foreach ($finalPrecos as $dados) {
                    $cacheKey = "preco_atual_{$dados['farmacia_id']}_{$dados['produto_id']}";
                    Cache::forget($cacheKey);
                }

            } catch (\Exception $e) {
                // Capturar erros durante a inserção em massa
                $conflitos[] = [
                    'message' => 'Erro ao inserir preços em massa: ' . $e->getMessage(),
                ];
            }
        }

        // Retorna o resultado como resposta JSON
        return response()->json([
            'success' => true,
            'resultados' => $resultados,
            'conflitos' => $conflitos,
        ], 200);
    }

    public function obterPrecoAtual(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'farmacia_id' => 'required|integer|exists:farmacias,farmacia_id',
            'produto_id' => 'required|integer|exists:produtos,produto_id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $farmaciaId = $request->query('farmacia_id');
        $produtoId = $request->query('produto_id');

        // Definindo uma chave única para o cache
        $cacheKey = "preco_atual_{$farmaciaId}_{$produtoId}";

        // Tentando obter o preço do cache
        // Le da projecao: um SELECT por chave primaria. A consulta anterior
        // ordenava `precos` por data e pegava a primeira — o que, com dois
        // precos na mesma data, devolvia qualquer um dos dois.
        $precoAtual = Cache::remember($cacheKey, 3600, function () use ($farmaciaId, $produtoId) {
            return DB::table('precos_atuais')
                ->where('farmacia_id', $farmaciaId)
                ->where('produto_id', $produtoId)
                ->first();
        });

        if (!$precoAtual) {
            return response()->json(['message' => 'Preço não encontrado.'], 404);
        }

        return response()->json($precoAtual);
    }
}
