<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Farmacia extends Model
{
    use HasFactory;

    protected $table = 'farmacias';
    protected $primaryKey = 'farmacia_id';

    protected $fillable = [
        'nome_farmacia'
    ];

    public $timestamps = false;


    public function informacoesProduto()
    {
        return $this->hasMany(InformacoesProduto::class, 'farmacia_id', 'farmacia_id');
    }

    public function precos()
    {
        return $this->hasMany(Preco::class, 'farmacia_id', 'farmacia_id');
    }
}
