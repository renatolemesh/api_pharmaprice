<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InformacoesProduto extends Model
{
    use HasFactory;

    protected $table = 'informacoes_produtos';
    protected $primaryKey = 'informacao_id';

    public function farmacia()
    {
        return $this->belongsTo(Farmacia::class, 'farmacia_id', 'farmacia_id');
    }

    public function produto()
    {
        return $this->belongsTo(Produto::class, 'produto_id', 'produto_id');
    }
}
