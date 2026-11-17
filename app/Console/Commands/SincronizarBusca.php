<?php

namespace App\Console\Commands;

use App\Support\BuscaSimilar;
use Illuminate\Console\Command;

/**
 * Leva os produtos do MySQL para o indice de busca por similaridade.
 *
 * Em uso normal nao precisa ser chamado: o cadastro de produto ja sincroniza.
 * Existe para a carga inicial e para o que acontece fora da API (insert manual
 * no MySQL, restauracao de backup).
 *
 *   php artisan busca:sincronizar              so os produtos novos
 *   php artisan busca:sincronizar --completo   apaga o indice e refaz
 */
class SincronizarBusca extends Command
{
    protected $signature = 'busca:sincronizar {--completo : Apaga o índice e reconstrói a partir do MySQL}';

    protected $description = 'Copia os produtos para o índice de busca por similaridade (Postgres)';

    public function handle(): int
    {
        $inicio = microtime(true);
        $total = BuscaSimilar::sincronizar((bool) $this->option('completo'));

        $this->info(sprintf('%d produtos indexados em %.1fs.', $total, microtime(true) - $inicio));

        return self::SUCCESS;
    }
}
