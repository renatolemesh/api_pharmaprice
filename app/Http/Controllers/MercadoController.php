<?php

namespace App\Http\Controllers;

use App\Support\PrecoMercado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Preco de mercado de uma lista de codigos de barras.
 *
 * Existe para a tela que compara a tabela de preco de quem usa o sistema contra
 * as redes monitoradas. O desenho tem uma consequencia que vale enunciar: a
 * requisicao manda EAN e recebe preco de concorrente — o preco de quem
 * pergunta nunca sai do navegador dele. Tabela de preco propria e dado
 * comercial, e nao ha motivo para ela passar por aqui: comparar e uma conta que
 * o navegador faz sozinho depois que recebe o mercado.
 *
 * Por isso tambem nao ha upload de arquivo nesta API. O arquivo e lido no
 * cliente; daqui so trafega codigo de barras.
 */
class MercadoController extends Controller
{
    /** Teto por requisicao. O cliente quebra a tabela dele em lotes. */
    private const MAXIMO_EANS = 1000;

    public function lote(Request $request)
    {
        $dados = $request->validate([
            'eans'   => 'required|array|min:1|max:' . self::MAXIMO_EANS,
            'eans.*' => 'required|string|max:20',
        ]);

        // Normaliza dos dois lados: planilha que passou pelo Excel perde o zero
        // a esquerda, e a base tem codigo curto que e SKU interno gravado no
        // campo de EAN. Comparar sem zeros a esquerda faz os dois se
        // encontrarem sem inventar correspondencia.
        $pedidos = [];
        foreach ($dados['eans'] as $bruto) {
            $limpo = preg_replace('/\D/', '', $bruto);
            if ($limpo === '' || $limpo === null) {
                continue;
            }
            $pedidos[ltrim($limpo, '0') ?: '0'] = $bruto;
        }

        if (!$pedidos) {
            return response()->json(['data' => []]);
        }

        $linhas = DB::table('precos_atuais as pa')
            ->join('produtos as prod', 'pa.produto_id', '=', 'prod.produto_id')
            ->join('farmacias as f', 'pa.farmacia_id', '=', 'f.farmacia_id')
            ->leftJoin('informacoes_produtos as ip', function ($join) {
                $join->on('ip.produto_id', '=', 'pa.produto_id')
                    ->on('ip.farmacia_id', '=', 'pa.farmacia_id');
            })
            ->whereIn(DB::raw("TRIM(LEADING '0' FROM prod.EAN)"), array_keys($pedidos))
            // Mesma regra da busca: produto que nenhuma coleta acha ha 30 dias
            // nao entra numa comparacao de preco atual.
            ->where(function ($q) {
                $q->where('ip.ativo', 1)->orWhereNull('ip.informacao_id');
            })
            ->select([
                'prod.EAN',
                'prod.descricao',
                'prod.laboratorio',
                'f.farmacia_id',
                'f.nome_farmacia',
                'f.url_base',
                'pa.preco',
                'pa.data',
                'ip.link',
            ])
            ->get();

        $porEan = [];
        foreach ($linhas as $linha) {
            $chave = ltrim(preg_replace('/\D/', '', $linha->EAN), '0') ?: '0';
            $porEan[$chave][] = $linha;
        }

        $resposta = [];
        foreach ($porEan as $chave => $itens) {
            $precos = array_map(fn ($i) => [
                'farmacia_id'   => (int) $i->farmacia_id,
                'nome_farmacia' => $i->nome_farmacia,
                'preco'         => (float) $i->preco,
            ], $itens);

            $resumo = PrecoMercado::resumir($precos);

            // Devolvido sob o EAN que o cliente mandou, e nao sob o da base: e
            // ele que aparece na planilha de quem pediu, e trocar isso obrigaria
            // o cliente a refazer a ligacao.
            $resposta[$pedidos[$chave]] = [
                'descricao'   => $itens[0]->descricao,
                'laboratorio' => $itens[0]->laboratorio,
                'mercado'     => $resumo,
                'precos'      => array_map(fn ($i) => [
                    'farmacia_id'   => (int) $i->farmacia_id,
                    'nome_farmacia' => $i->nome_farmacia,
                    'preco'         => (float) $i->preco,
                    'data'          => $i->data,
                    'url_base'      => $i->url_base,
                    'link'          => $i->link,
                ], $itens),
            ];
        }

        return response()->json(['data' => $resposta]);
    }
}
