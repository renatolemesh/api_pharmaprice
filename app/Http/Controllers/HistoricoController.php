<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Historico de precos, agrupado por produto e farmacia.
 *
 * A paginacao aqui conta GRUPOS, nao linhas de preco. A versao anterior
 * paginava a tabela `precos` e so depois agrupava o resultado, e isso quebrava
 * de dois jeitos:
 *
 * 1. O numero de cartoes por pagina oscilava. As 100 linhas eram sempre 100,
 *    mas um produto que a rede remarca toda semana consumia 47 delas sozinho e
 *    outro parado consumia 2 — a mesma busca mostrava 10 cartoes numa pagina e
 *    5 na seguinte.
 *
 * 2. Pior: o corte caia no meio de um produto. Medido em producao, a Maxalgina
 *    da Nissei (EAN 7899470807362) tinha 54 mudancas de preco divididas em dois
 *    cartoes — 21 pontos no fim da pagina 3 (30/04 a 14/08/2026) e 33 no comeco
 *    da pagina 4 (26/06/2024 a 27/04/2026). Dois graficos parciais da mesma
 *    serie, em ordem trocada, sem nada na tela dizendo que era meio historico.
 *
 * Custa uma consulta a mais: primeiro os grupos da pagina, depois os precos so
 * desses grupos. Em troca, "por pagina" passa a querer dizer o que a tela
 * mostra, e nenhuma serie sai cortada.
 */
class HistoricoController extends Controller
{
    /**
     * Cartoes por pagina.
     *
     * Vinte e nao cem porque agora a unidade e o cartao: a media da paginacao
     * antiga ficava em torno de sete por pagina, e o grafico de cada um so e
     * montado quando o cartao e expandido.
     */
    private const GRUPOS_POR_PAGINA = 20;

    public function historico(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ean' => 'nullable|string|max:15',
            'descricao' => 'nullable|string|max:255',
            'farmacia' => 'nullable|string',
            'data-inicio' => 'nullable|date',
            'data-fim' => 'nullable|date',
            'page' => 'nullable|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $filtros = [
            'ean'         => $request->query('ean'),
            'descricao'   => $request->query('descricao'),
            'farmacia'    => $request->query('farmacia'),
            'data_inicio' => $request->query('data-inicio'),
            'data_fim'    => $request->query('data-fim'),
        ];

        if ($filtros['descricao']) {
            $filtros['descricao'] = str_replace('+', ' ', $filtros['descricao']);
        }

        if ($request->query->has('no_paginate')) {
            return $this->streamCompleto($filtros);
        }

        $grupos = $this->consultaGrupos($filtros)
            /*
             * Ordem explicita e com desempate por id.
             *
             * A consulta antiga nao tinha `order by` nenhum: a ordem era a que o
             * plano de execucao produzisse, e `LIMIT/OFFSET` sobre ordem
             * indefinida pode pular ou repetir linha entre uma pagina e outra —
             * ainda mais com o coletor inserindo precos no meio da navegacao.
             * Descricao sozinha tambem nao basta: produtos distintos compartilham
             * o mesmo texto (Nan Sem Lactose 400g existe com EAN suico e
             * europeu), entao o id entra como desempate.
             */
            ->orderBy('produtos.descricao')
            ->orderBy('precos_atuais.produto_id')
            ->orderBy('precos_atuais.farmacia_id')
            ->paginate(self::GRUPOS_POR_PAGINA);

        if ($grupos->isEmpty()) {
            return response()->json(['message' => 'Nenhum resultado encontrado.'], 200);
        }

        $precos = $this->precosDosGrupos($grupos->items(), $filtros);

        $dados = [];
        foreach ($grupos->items() as $grupo) {
            $chave = $grupo->produto_id . '-' . $grupo->farmacia_id;

            $dados[] = [
                'descricao'     => $grupo->descricao,
                'EAN'           => $grupo->EAN,
                'nome_farmacia' => $grupo->nome_farmacia,
                'precos'        => $precos[$chave] ?? [],
            ];
        }

        return response()->json([
            'data'         => $dados,
            'current_page' => $grupos->currentPage(),
            'last_page'    => $grupos->lastPage(),
            'per_page'     => $grupos->perPage(),
            // Agora `total` conta cartoes, e nao linhas de preco. Antes a tela
            // mostrava 5 cartoes e o rodape dizia "9.084 resultados".
            'total'        => $grupos->total(),
        ]);
    }

    /**
     * Os pares (produto, farmacia) que a busca alcanca, um por linha.
     *
     * A lista sai de `precos_atuais` e nao de um `GROUP BY` sobre `precos`
     * porque ali o par JA e a chave primaria — nao ha o que agrupar. A troca so
     * e legitima porque a projecao cobre todo par que existe no historico:
     * conferido em producao, 208.573 pares dos dois lados e zero orfaos, e e o
     * que `ReconciliarPrecosAtuais` mantem e `PrecoAtualTest` cobra. Se essa
     * invariante cair, some historico da tela sem erro nenhum.
     *
     * Medido com `descricao=500mg`: 240ms aqui contra 847ms agrupando `precos`.
     */
    private function consultaGrupos(array $filtros)
    {
        $query = DB::table('precos_atuais')
            ->join('produtos', 'precos_atuais.produto_id', '=', 'produtos.produto_id')
            ->join('farmacias', 'precos_atuais.farmacia_id', '=', 'farmacias.farmacia_id')
            ->select(
                'precos_atuais.produto_id',
                'produtos.descricao',
                'produtos.EAN',
                'precos_atuais.farmacia_id',
                'farmacias.nome_farmacia'
            );

        $this->aplicarProdutoEFarmacia($query, $filtros, 'precos_atuais');

        /*
         * O periodo vira `EXISTS` em vez de filtro direto.
         *
         * `precos_atuais` guarda so o preco vigente, entao filtrar a data dela
         * responderia "o preco de hoje esta na janela?", que nao e a pergunta —
         * a pergunta e se o par teve ALGUMA mudanca no periodo. O `EXISTS` para
         * na primeira linha que encontra, e mede 333ms no mesmo caso amplo.
         */
        if ($filtros['data_inicio'] || $filtros['data_fim']) {
            $query->whereExists(function ($sub) use ($filtros) {
                $sub->select(DB::raw(1))
                    ->from('precos')
                    ->whereColumn('precos.produto_id', 'precos_atuais.produto_id')
                    ->whereColumn('precos.farmacia_id', 'precos_atuais.farmacia_id');

                $this->aplicarPeriodo($sub, $filtros);
            });
        }

        return $query;
    }

    /** Filtro de produto (EAN ou descricao) e de farmacia, comuns as duas consultas. */
    private function aplicarProdutoEFarmacia($query, array $filtros, string $tabela): void
    {
        if ($filtros['ean']) {
            $query->where('produtos.EAN', $filtros['ean']);
        } elseif ($filtros['descricao']) {
            $query->where('produtos.descricao', 'like', '%' . $filtros['descricao'] . '%');
        }

        if ($filtros['farmacia']) {
            $ids = explode(' ', str_replace('+', ' ', $filtros['farmacia']));
            $query->whereIn($tabela . '.farmacia_id', $ids);
        }
    }

    /** O recorte de datas, que precisa valer tambem na busca dos precos. */
    private function aplicarPeriodo($query, array $filtros): void
    {
        if ($filtros['data_inicio'] && $filtros['data_fim']) {
            $query->whereBetween('precos.data', [$filtros['data_inicio'], $filtros['data_fim']]);
        } elseif ($filtros['data_inicio']) {
            $query->where('precos.data', '>=', $filtros['data_inicio']);
        } elseif ($filtros['data_fim']) {
            $query->where('precos.data', '<=', $filtros['data_fim']);
        }
    }

    /**
     * Todos os precos dos grupos da pagina, indexados por produto-farmacia.
     *
     * O par (produto, farmacia) entra como tupla numa unica clausula `IN`, e nao
     * como dois `whereIn` separados: dois `whereIn` formariam o produto
     * cartesiano dos ids e trariam pares que nao estao nesta pagina.
     *
     * @param  array<int, object>  $grupos
     * @return array<string, array<int, array{preco: mixed, data: mixed}>>
     */
    private function precosDosGrupos(array $grupos, array $filtros): array
    {
        $pares = array_map(
            fn ($g) => [$g->produto_id, $g->farmacia_id],
            $grupos
        );

        $tuplas = implode(', ', array_fill(0, count($pares), '(?, ?)'));

        $query = DB::table('precos')
            ->select('produto_id', 'farmacia_id', 'preco', 'data')
            ->whereRaw("(precos.produto_id, precos.farmacia_id) IN ($tuplas)", array_merge(...$pares));

        // O periodo vale aqui tambem: sem isto, filtrar por data escolheria os
        // grupos pelo recorte mas devolveria a serie inteira de cada um.
        $this->aplicarPeriodo($query, $filtros);

        $indexado = [];
        foreach ($query->orderBy('data')->get() as $linha) {
            $indexado[$linha->produto_id . '-' . $linha->farmacia_id][] = [
                'preco' => $linha->preco,
                'data'  => $linha->data,
            ];
        }

        return $indexado;
    }

    /** Exportacao sem paginacao: a busca inteira, em streaming. */
    private function streamCompleto(array $filtros)
    {
        $query = DB::table('precos')
            ->join('produtos', 'precos.produto_id', '=', 'produtos.produto_id')
            ->join('farmacias', 'precos.farmacia_id', '=', 'farmacias.farmacia_id')
            ->select(
                'produtos.descricao',
                'produtos.EAN',
                'farmacias.nome_farmacia',
                'precos.preco',
                'precos.data'
            )
            // `chunk` precisa de ordem estavel para nao pular nem repetir linha
            // entre um lote e outro.
            ->orderBy('precos.preco_id');

        $this->aplicarProdutoEFarmacia($query, $filtros, 'precos');
        $this->aplicarPeriodo($query, $filtros);

        return response()->stream(function () use ($query) {
            echo '{"data":[';
            $historico = [];

            $query->chunk(2000, function ($items) use (&$historico) {
                foreach ($items as $resultado) {
                    $key = $resultado->descricao . '-' . $resultado->EAN . '-' . $resultado->nome_farmacia;
                    if (!isset($historico[$key])) {
                        $historico[$key] = [
                            'descricao' => $resultado->descricao,
                            'EAN' => $resultado->EAN,
                            'nome_farmacia' => $resultado->nome_farmacia,
                            'precos' => []
                        ];
                    }
                    $historico[$key]['precos'][] = [
                        'preco' => $resultado->preco,
                        'data' => $resultado->data
                    ];
                }
            });

            $first = true;
            foreach ($historico as $item) {
                if (!$first) echo ',';
                echo json_encode($item);
                $first = false;
            }

            echo ']}';
        }, 200, [
            'Content-Type' => 'application/json',
        ]);
    }
}
