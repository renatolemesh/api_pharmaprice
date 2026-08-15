<?php

namespace App\Console\Commands;

use App\Models\ExecucaoColeta;
use App\Models\InformacoesProduto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DesativarProdutosObsoletos extends Command
{
    protected $signature = 'coletas:desativar-obsoletos
                            {--dias=30 : Dias sem coleta bem-sucedida para considerar obsoleto}
                            {--janela-execucao=7 : Dias dentro dos quais a farmacia precisa ter concluido uma coleta}
                            {--cobertura-minima=50 : % do catalogo ativo que a coleta recente precisa ter visto}
                            {--farmacia= : Limita a uma farmacia_id}
                            {--forcar : Ignora as travas de seguranca}
                            {--dry-run : Apenas relata, sem alterar nada}';

    protected $description = 'Desativa produtos sem coleta bem-sucedida ha mais de N dias';

    public function handle(): int
    {
        $dias      = (int) $this->option('dias');
        $janela    = (int) $this->option('janela-execucao');
        $cobertura = (int) $this->option('cobertura-minima');
        $dryRun    = (bool) $this->option('dry-run');
        $forcar    = (bool) $this->option('forcar');
        $limite    = now()->subDays($dias);
        $agora     = now();

        $farmacias = DB::table('informacoes_produtos as ip')
            ->join('farmacias as f', 'f.farmacia_id', '=', 'ip.farmacia_id')
            ->when($this->option('farmacia'), fn ($q) => $q->where('ip.farmacia_id', (int) $this->option('farmacia')))
            ->groupBy('ip.farmacia_id', 'f.nome_farmacia')
            ->select('ip.farmacia_id', 'f.nome_farmacia')
            ->get();

        if ($farmacias->isEmpty()) {
            $this->warn('Nenhuma farmacia com produtos cadastrados.');
            return self::SUCCESS;
        }

        $totalDesativado = 0;

        foreach ($farmacias as $farmacia) {
            $ativos = InformacoesProduto::where('farmacia_id', $farmacia->farmacia_id)
                ->where('ativo', true)
                ->count();

            if (!$forcar) {
                $motivo = $this->motivoParaNaoAgir($farmacia->farmacia_id, $janela, $cobertura, $ativos);
                if ($motivo !== null) {
                    $this->warn(sprintf('[%s] pulada: %s', $farmacia->nome_farmacia, $motivo));
                    continue;
                }
            }

            $query = InformacoesProduto::where('farmacia_id', $farmacia->farmacia_id)
                ->where('ativo', true)
                ->where(function ($q) use ($limite) {
                    $q->whereNull('ultima_coleta_em')->orWhere('ultima_coleta_em', '<', $limite);
                });

            $quantidade = (clone $query)->count();

            if ($quantidade === 0) {
                $this->line(sprintf('[%s] nada a desativar.', $farmacia->nome_farmacia));
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('[%s] %d produtos seriam desativados.', $farmacia->nome_farmacia, $quantidade));
                $totalDesativado += $quantidade;
                continue;
            }

            $query->update([
                'ativo'         => false,
                'desativado_em' => $agora,
            ]);

            $this->info(sprintf('[%s] %d produtos desativados.', $farmacia->nome_farmacia, $quantidade));
            $totalDesativado += $quantidade;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d produtos sem coleta ha mais de %d dias.',
            $dryRun ? '[dry-run] Seriam desativados:' : 'Total desativado:',
            $totalDesativado,
            $dias
        ));

        return self::SUCCESS;
    }

    /**
     * Travas de seguranca. Devolve o motivo para NAO desativar, ou null.
     *
     * Sao duas, e a segunda so ficou obvia testando com dados reais:
     *
     *  1. Sem coleta concluida recente, o dado obsoleto e nosso, nao da
     *     farmacia - um container parado duas semanas esvaziaria o catalogo.
     *
     *  2. Existir uma coleta concluida nao basta: uma rodada que quebrou no
     *     meio e viu 1% do catalogo tambem termina "concluida", e sozinha
     *     autorizaria desativar os outros 99%. A coleta recente precisa ter
     *     enxergado uma fatia critivel do que esta ativo.
     */
    private function motivoParaNaoAgir(int $farmaciaId, int $janela, int $cobertura, int $ativos): ?string
    {
        $execucao = ExecucaoColeta::where('farmacia_id', $farmaciaId)
            ->where('status', 'concluida')
            ->where('finalizado_em', '>=', now()->subDays($janela))
            ->orderByDesc('itens_vistos')
            ->first();

        if (!$execucao) {
            return sprintf(
                'nenhuma coleta concluida nos ultimos %d dias. Use --forcar para ignorar.',
                $janela
            );
        }

        if ($ativos > 0) {
            $vistoPorcento = (int) round($execucao->itens_vistos * 100 / $ativos);

            if ($vistoPorcento < $cobertura) {
                return sprintf(
                    'ultima coleta viu %d de %d produtos ativos (%d%%), abaixo do minimo de %d%%. '
                    . 'Coleta provavelmente incompleta.',
                    $execucao->itens_vistos,
                    $ativos,
                    $vistoPorcento,
                    $cobertura
                );
            }
        }

        return null;
    }
}
