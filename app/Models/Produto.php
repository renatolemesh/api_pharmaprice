<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Produto extends Model
{
    use HasFactory;

    protected $table = 'produtos';
    protected $primaryKey = 'produto_id';

    public function informacoesProduto()
    {
        return $this->hasMany(InformacoesProduto::class, 'produto_id', 'produto_id');
    }

    public function precos()
    {
        return $this->hasMany(Preco::class, 'produto_id', 'produto_id');
    }
}