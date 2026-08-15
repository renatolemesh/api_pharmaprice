<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExecucaoColeta extends Model
{
    use HasFactory;

    protected $table = 'execucoes_coleta';
    protected $primaryKey = 'execucao_id';

    public $timestamps = false;

    protected $fillable = [
        'farmacia_id',
        'script',
        'iniciado_em',
        'finalizado_em',
        'itens_vistos',
        'itens_com_preco',
        'precos_alterados',
        'links_atualizados',
        'skus_atualizados',
        'nao_resolvidos',
        'erros',
        'status',
        'observacao',
    ];

    protected $casts = [
        'iniciado_em' => 'datetime',
        'finalizado_em' => 'datetime',
    ];

    public function farmacia()
    {
        return $this->belongsTo(Farmacia::class, 'farmacia_id', 'farmacia_id');
    }
}
