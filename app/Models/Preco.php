<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Preco extends Model
{
    use HasFactory;

    protected $table = 'precos';
    protected $primaryKey = 'preco_id';

    protected $fillable = [
        'farmacia_id',
        'produto_id',
        'preco',
        'data'
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
