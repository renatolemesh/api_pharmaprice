<?php

namespace App\Http\Controllers;

use App\Models\ExecucaoColeta;
use App\Models\InformacoesProduto;
use App\Support\PrecoAtual;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Heartbeat de coleta.
 *
 * O scraper reporta aqui TUDO que viu numa passada, e nao apenas o que mudou
 * de preco. Numa unica transacao por lote isso:
 *
 *   1. carimba "visto agora" no par (farmacia, produto);
 *   2. corrige link e sku quando a farmacia mudou a URL do produto;
 *   3. insere preco apenas quando ele de fato mudou;
 *   4. zera (ou incrementa) o contador de falhas consecutivas;
 *   5. enfileira em `links` o que nao foi possivel identificar.
 *
 * Sem isso a API nao conseguia distinguir "preco estavel" de "produto morto",
 * porque as duas situacoes produzem exatamente o mesmo registro em `precos`.
 */
class ColetaController extends Controller
{
    /** Status que significam "produto visto e coletado com sucesso". */
    private const STATUS_OK = 'ok';

    /** Diferenca de preco ignorada, alinhada com PrecoController@store. */
    private const TOLERANCIA_PRECO = 0.01;

    private const PRECO_MAXIMO = 100000;

    public function store(Request $request)
    {
        $dados = $request->validate([
            'farmacia_id'        => 'required|integer|exists:farmacias,farmacia_id',
            'execucao_id'        => 'nullable|integer|exists:execucoes_coleta,execucao_id',
            'data'               => 'nullable|date',
            'itens'              => 'required|array|min:1|max:1000',
            'itens.*.produto_id' => 'nullable|integer',
            'itens.*.sku'        => 'nullable|string|max:50',
            'itens.*.link'       => 'nullable|string|max:255',
            'itens.*.preco'      => 'nullable|numeric',
            'itens.*.status'     => 'nullable|string|max:30',
        ]);

        $farmaciaId = (int) $dados['farmacia_id'];
        $execucaoId = $dados['execucao_id'] ?? null;
        $dataPreco  = isset($dados['data']) ? Carbon::parse($dados['data'])->toDateString() : now()->toDateString();
        $agora      = now();

        $itens = $this->normalizarItens($dados['itens']);

        try {
            $resultado = DB::transaction(function () use ($itens, $farmaciaId, $dataPreco, $agora, $execucaoId) {
                return $this->processarLote($itens, $farmaciaId, $dataPreco, $agora, $execucaoId);
            });
        } catch (\Exception $e) {
            Log::error('Erro ao registrar coleta', [
                'farmacia_id' => $farmaciaId,
                'itens'       => count($itens),
                'message'     => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Erro interno ao registrar coleta'], 500);
        }

        return response()->json($resultado, 200);
    }

    /**
     * Normaliza e classifica cada item recebido.
     *
     * Preco fora de faixa nao anula a coleta - o produto foi visto na pagina.
     * Ele apenas deixa de virar registro de preco e fica marcado como suspeito.
     */
    private function normalizarItens(array $brutos): array
    {
        $itens = [];

        foreach ($brutos as $bruto) {
            $sku   = isset($bruto['sku']) ? trim((string) $bruto['sku']) : null;
            $link  = isset($bruto['link']) ? trim((string) $bruto['link']) : null;
            $preco = isset($bruto['preco']) ? (float) $bruto['preco'] : null;
            $status = $bruto['status'] ?? null;

            if ($preco !== null && ($preco <= 0 || $preco > self::PRECO_MAXIMO)) {
                $preco  = null;
                $status = $status ?? 'preco_invalido';
            }

            $itens[] = [
                'produto_id' => isset($bruto['produto_id']) ? (int) $bruto['produto_id'] : null,
                'sku'        => $sku !== '' ? $sku : null,
                'link'       => $link !== '' ? $link : null,
                'preco'      => $preco,
                'status'     => $status ?: ($preco !== null ? self::STATUS_OK : 'sem_preco'),
            ];
        }

        return $itens;
    }

    private function processarLote(array $itens, int $farmaciaId, string $dataPreco, Carbon $agora, ?int $execucaoId): array
    {
        [$porProduto, $porSku, $porLink] = $this->carregarCandidatos($itens, $farmaciaId);

        $sucessos      = [];
        $falhas        = [];
        $naoResolvidos = [];
        $conflitos     = [];

        foreach ($itens as $item) {
            [$info, $via] = $this->resolver($item, $porProduto, $porSku, $porLink);

            if ($info === null) {
                $naoResolvidos[] = ['sku' => $item['sku'], 'link' => $item['link']];
                continue;
            }

            // Resolvido pela URL, mas o sku da pagina discorda do sku gravado:
            // a farmacia reaproveitou o slug para outro produto. Carimbar coleta
            // aqui apagaria a evidencia de que o produto antigo morreu, e o preco
            // iria para o produto errado. Trata como nao identificado.
            if ($via === 'link' && $item['sku'] !== null && $info->sku !== null
                && (string) $info->sku !== '' && (string) $info->sku !== $item['sku']) {
                $conflitos[] = [
                    'link'         => $item['link'],
                    'produto_id'   => $info->produto_id,
                    'sku_recebido' => $item['sku'],
                    'sku_atual'    => $info->sku,
                    'motivo'       => 'link aponta para produto com sku diferente',
                ];
                $naoResolvidos[] = ['sku' => $item['sku'], 'link' => $item['link']];
                continue;
            }

            $item['produto_id'] = (int) $info->produto_id;
            $item['info']       = $info;

            if ($item['status'] === self::STATUS_OK) {
                $sucessos[] = $item;
            } else {
                $falhas[] = $item;
            }
        }

        $precosInseridos  = $this->inserirPrecosAlterados($sucessos, $farmaciaId, $dataPreco);
        $atualizacoes     = $this->carimbarSucessos($sucessos, $farmaciaId, $agora);
        $this->carimbarFalhas($falhas, $farmaciaId, $agora);
        $linksEnfileirados = $this->enfileirarNaoResolvidos($naoResolvidos, $farmaciaId);

        $contadores = [
            'itens_recebidos'    => count($itens),
            'resolvidos'         => count($sucessos) + count($falhas),
            'coletados'          => count($sucessos),
            'falhas'             => count($falhas),
            'nao_resolvidos'     => count($naoResolvidos),
            'precos_alterados'   => $precosInseridos,
            'links_atualizados'  => $atualizacoes['links'],
            'skus_atualizados'   => $atualizacoes['skus'],
            'links_enfileirados' => $linksEnfileirados,
            'conflitos'          => $conflitos,
            // A lista, e nao so a contagem: farmacias que ja trazem o EAN na
            // mao usam isto para cadastrar o produto na hora, em vez de esperar
            // a fila de links ser processada depois.
            'nao_resolvidos_itens' => $naoResolvidos,
        ];

        $this->acumularNaExecucao($execucaoId, $contadores);

        return $contadores;
    }

    /**
     * Uma unica consulta traz todos os candidatos do lote, por produto_id, sku
     * ou link. Resolver item a item custaria centenas de round-trips por lote.
     */
    private function carregarCandidatos(array $itens, int $farmaciaId): array
    {
        $produtoIds = array_values(array_unique(array_filter(array_column($itens, 'produto_id'))));
        $skus       = array_values(array_unique(array_filter(array_column($itens, 'sku'))));
        $links      = array_values(array_unique(array_filter(array_column($itens, 'link'))));

        if (!$produtoIds && !$skus && !$links) {
            return [collect(), collect(), collect()];
        }

        $infos = InformacoesProduto::where('farmacia_id', $farmaciaId)
            ->where(function ($query) use ($produtoIds, $skus, $links) {
                if ($produtoIds) {
                    $query->orWhereIn('produto_id', $produtoIds);
                }
                if ($skus) {
                    $query->orWhereIn('sku', $skus);
                }
                if ($links) {
                    $query->orWhereIn('link', $links);
                }
            })
            ->get();

        return [
            $infos->keyBy('produto_id'),
            // groupBy (e nao keyBy) para detectar sku/link ambiguo em vez de
            // silenciosamente ficar com o ultimo registro encontrado.
            $infos->filter(fn ($i) => $i->sku !== null && $i->sku !== '')->groupBy('sku'),
            $infos->filter(fn ($i) => $i->link !== null && $i->link !== '')->groupBy('link'),
        ];
    }

    /**
     * Ordem de identificacao: produto_id > sku > link.
     *
     * O sku vem antes do link de proposito. Ele e o identificador interno da
     * farmacia e sobrevive a mudanca de slug; o link e justamente o que costuma
     * mudar - e que hoje esta errado no banco.
     */
    private function resolver(array $item, $porProduto, $porSku, $porLink): array
    {
        if ($item['produto_id'] && $porProduto->has($item['produto_id'])) {
            return [$porProduto->get($item['produto_id']), 'produto_id'];
        }

        if ($item['sku'] !== null) {
            $grupo = $porSku->get($item['sku']);
            if ($grupo && $grupo->count() === 1) {
                return [$grupo->first(), 'sku'];
            }
        }

        if ($item['link'] !== null) {
            $grupo = $porLink->get($item['link']);
            if ($grupo && $grupo->count() === 1) {
                return [$grupo->first(), 'link'];
            }
        }

        return [null, null];
    }

    /**
     * Insere em `precos` somente o que mudou - a tabela continua sendo um log
     * de alteracoes. Quem responde "foi visto hoje?" agora e ultima_coleta_em.
     */
    private function inserirPrecosAlterados(array $sucessos, int $farmaciaId, string $dataPreco): int
    {
        $comPreco = array_filter($sucessos, fn ($i) => $i['preco'] !== null);

        if (!$comPreco) {
            return 0;
        }

        $produtoIds = array_values(array_unique(array_column($comPreco, 'produto_id')));

        // Le de `precos_atuais`, nao mais da `latest_precos_view`: a view
        // refazia o GROUP BY da tabela inteira de precos a cada lote coletado.
        $precosAtuais = PrecoAtual::daFarmacia($farmaciaId, $produtoIds);

        $novos = [];
        foreach ($comPreco as $item) {
            $atual = $precosAtuais->get($item['produto_id']);

            if ($atual !== null && abs((float) $atual->preco - $item['preco']) <= self::TOLERANCIA_PRECO) {
                continue;
            }

            // Um mesmo produto pode aparecer duas vezes no lote (categorias que
            // se sobrepoem no site). Fica com a ultima ocorrencia.
            $novos[$item['produto_id']] = [
                'farmacia_id' => $farmaciaId,
                'produto_id'  => $item['produto_id'],
                'preco'       => $item['preco'],
                'data'        => $dataPreco,
                // O que este preco substitui. Vem de `precos_atuais`, que e a
                // projecao verificada de `precos` — no instante do insert ela e,
                // por definicao, o preco anterior. Nulo quando e o primeiro
                // preco conhecido do par, e nao "desconhecido".
                'preco_anterior' => $atual->preco ?? null,
                'data_anterior'  => $atual->data ?? null,
            ];
        }

        if (!$novos) {
            return 0;
        }

        // Log e projecao na mesma transacao: ou as duas valem, ou nenhuma.
        // `precos_atuais` fora de sincronia com `precos` seria pior que lento —
        // seria uma busca respondendo preco que nao existe.
        foreach (array_chunk(array_values($novos), 500) as $lote) {
            DB::transaction(function () use ($lote) {
                DB::table('precos')->insert($lote);
                PrecoAtual::projetar($lote);
            });
        }

        foreach (array_keys($novos) as $produtoId) {
            Cache::forget("preco_atual_{$farmaciaId}_{$produtoId}");
        }

        return count($novos);
    }

    /**
     * Carimba a coleta e corrige link/sku divergentes.
     *
     * Este e o ponto que faltava: ate agora informacoes_produtos so era escrito
     * na insercao, entao um link nascia congelado e nunca mais era corrigido.
     */
    private function carimbarSucessos(array $sucessos, int $farmaciaId, Carbon $agora): array
    {
        if (!$sucessos) {
            return ['links' => 0, 'skus' => 0];
        }

        $linksAtualizados = 0;
        $skusAtualizados  = 0;
        $linhas           = [];

        foreach ($sucessos as $item) {
            $info = $item['info'];
            $link = $item['link'] ?? $info->link;
            $sku  = $item['sku'] ?? $info->sku;

            if ($item['link'] !== null && $item['link'] !== $info->link) {
                $linksAtualizados++;
            }
            if ($item['sku'] !== null && (string) $item['sku'] !== (string) $info->sku) {
                $skusAtualizados++;
            }

            $linhas[$item['produto_id']] = [
                'farmacia_id'         => $farmaciaId,
                'produto_id'          => $item['produto_id'],
                'link'                => $link,
                'sku'                 => $sku,
                'ativo'               => 1,
                'ultima_coleta_em'    => $agora,
                'ultima_tentativa_em' => $agora,
                'falhas_consecutivas' => 0,
                'ultimo_status'       => $item['status'],
                // Reativa sozinho: se voltou a ser coletado, voltou a existir.
                'desativado_em'       => null,
            ];
        }

        foreach (array_chunk(array_values($linhas), 500) as $lote) {
            InformacoesProduto::upsert(
                $lote,
                ['farmacia_id', 'produto_id'],
                ['link', 'sku', 'ativo', 'ultima_coleta_em', 'ultima_tentativa_em',
                 'falhas_consecutivas', 'ultimo_status', 'desativado_em']
            );
        }

        return ['links' => $linksAtualizados, 'skus' => $skusAtualizados];
    }

    /**
     * Produto encontrado no catalogo mas sem preco utilizavel: registra a
     * tentativa sem carimbar coleta, para que ele envelheca ate a desativacao.
     */
    private function carimbarFalhas(array $falhas, int $farmaciaId, Carbon $agora): void
    {
        if (!$falhas) {
            return;
        }

        $porStatus = [];
        foreach ($falhas as $item) {
            $porStatus[$item['status']][] = $item['produto_id'];
        }

        foreach ($porStatus as $status => $produtoIds) {
            foreach (array_chunk(array_unique($produtoIds), 500) as $lote) {
                InformacoesProduto::where('farmacia_id', $farmaciaId)
                    ->whereIn('produto_id', $lote)
                    ->update([
                        'ultima_tentativa_em' => $agora,
                        'ultimo_status'       => $status,
                        'falhas_consecutivas' => DB::raw('falhas_consecutivas + 1'),
                    ]);
            }
        }
    }

    /**
     * O que nao foi identificado vira link na fila para o requestproduto
     * resolver por EAN. Antes o scraper baixava a tabela inteira de links a
     * cada produto nao encontrado so para decidir isso.
     */
    private function enfileirarNaoResolvidos(array $naoResolvidos, int $farmaciaId): int
    {
        $links = collect($naoResolvidos)->pluck('link')->filter()->unique()->values();

        if ($links->isEmpty()) {
            return 0;
        }

        $existentes = DB::table('links')
            ->where('farmacia_id', $farmaciaId)
            ->whereIn('link', $links->all())
            ->pluck('link');

        $novos = $links->diff($existentes)
            ->map(fn ($link) => ['farmacia_id' => $farmaciaId, 'link' => $link])
            ->values();

        if ($novos->isEmpty()) {
            return 0;
        }

        foreach ($novos->chunk(500) as $lote) {
            DB::table('links')->insert($lote->all());
        }

        Cache::forget("links_farmacia_{$farmaciaId}");

        return $novos->count();
    }

    private function acumularNaExecucao(?int $execucaoId, array $contadores): void
    {
        if (!$execucaoId) {
            return;
        }

        ExecucaoColeta::where('execucao_id', $execucaoId)->update([
            'itens_vistos'      => DB::raw('itens_vistos + ' . (int) $contadores['itens_recebidos']),
            'itens_com_preco'   => DB::raw('itens_com_preco + ' . (int) $contadores['coletados']),
            'precos_alterados'  => DB::raw('precos_alterados + ' . (int) $contadores['precos_alterados']),
            'links_atualizados' => DB::raw('links_atualizados + ' . (int) $contadores['links_atualizados']),
            'skus_atualizados'  => DB::raw('skus_atualizados + ' . (int) $contadores['skus_atualizados']),
            'nao_resolvidos'    => DB::raw('nao_resolvidos + ' . (int) $contadores['nao_resolvidos']),
        ]);
    }

    public function iniciarExecucao(Request $request)
    {
        $dados = $request->validate([
            'farmacia_id' => 'required|integer|exists:farmacias,farmacia_id',
            'script'      => 'nullable|string|max:50',
        ]);

        $execucao = ExecucaoColeta::create([
            'farmacia_id' => $dados['farmacia_id'],
            'script'      => $dados['script'] ?? null,
            'iniciado_em' => now(),
            'status'      => 'rodando',
        ]);

        return response()->json($execucao, 201);
    }

    public function finalizarExecucao(Request $request, int $execucaoId)
    {
        $dados = $request->validate([
            'status'     => 'nullable|string|in:concluida,falhou,interrompida',
            'erros'      => 'nullable|integer|min:0',
            'observacao' => 'nullable|string|max:2000',
        ]);

        $execucao = ExecucaoColeta::find($execucaoId);

        if (!$execucao) {
            return response()->json(['error' => 'Execucao nao encontrada'], 404);
        }

        $execucao->status        = $dados['status'] ?? 'concluida';
        $execucao->finalizado_em = now();
        $execucao->erros         = $dados['erros'] ?? $execucao->erros;
        $execucao->observacao    = $dados['observacao'] ?? $execucao->observacao;
        $execucao->save();

        return response()->json($execucao, 200);
    }

    /**
     * Panorama por farmacia: quantos produtos ativos, quantos foram vistos
     * recentemente e quando cada scraper rodou pela ultima vez.
     */
    public function saude()
    {
        $limite24h = now()->subDay();
        $limite7d  = now()->subDays(7);
        $limite30d = now()->subDays(30);

        $resumo = DB::table('informacoes_produtos as ip')
            ->join('farmacias as f', 'f.farmacia_id', '=', 'ip.farmacia_id')
            ->groupBy('ip.farmacia_id', 'f.nome_farmacia')
            ->select('ip.farmacia_id', 'f.nome_farmacia')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(ip.ativo = 1) as ativos')
            ->selectRaw('SUM(ip.ativo = 0) as inativos')
            ->selectRaw('SUM(ip.ultima_coleta_em >= ?) as coletados_24h', [$limite24h])
            ->selectRaw('SUM(ip.ultima_coleta_em >= ?) as coletados_7d', [$limite7d])
            ->selectRaw('SUM(ip.ativo = 1 AND (ip.ultima_coleta_em IS NULL OR ip.ultima_coleta_em < ?)) as obsoletos_30d', [$limite30d])
            ->selectRaw('MAX(ip.ultima_coleta_em) as ultima_coleta')
            ->get()
            ->keyBy('farmacia_id');

        $ultimasExecucoes = DB::table('execucoes_coleta as e')
            ->whereRaw('e.execucao_id = (SELECT MAX(e2.execucao_id) FROM execucoes_coleta e2 WHERE e2.farmacia_id = e.farmacia_id)')
            ->get()
            ->keyBy('farmacia_id');

        $farmacias = $resumo->map(function ($linha) use ($ultimasExecucoes) {
            $linha->ultima_execucao = $ultimasExecucoes->get($linha->farmacia_id);
            return $linha;
        })->values();

        return response()->json(['farmacias' => $farmacias]);
    }
}
