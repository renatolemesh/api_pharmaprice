<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InformacoesProduto extends Model
{
    use HasFactory;

    protected $table = 'informacoes_produtos';
    protected $primaryKey = 'informacao_id';

    protected $fillable = [
        'farmacia_id',
        'produto_id',
        'link',
        'sku',
        'ativo',
        'ultima_coleta_em',
        'ultima_tentativa_em',
        'falhas_consecutivas',
        'ultimo_status',
        'desativado_em',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'ultima_coleta_em' => 'datetime',
        'ultima_tentativa_em' => 'datetime',
        'desativado_em' => 'datetime',
    ];

    public $timestamps = false;

    public function farmacia()
    {
        return $this->belongsTo(Farmacia::class, 'farmacia_id', 'farmacia_id');
    }

    public function produto()
    {
        return $this->belongsTo(Produto::class, 'produto_id', 'produto_id');
    }
}
