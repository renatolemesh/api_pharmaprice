<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Link extends Model
{
    use HasFactory;

    protected $table = 'links';

    protected $fillable = [
        'farmacia_id',
        'link'
    ];

    public $timestamps = false;

    // A tabela nao tem chave primaria. Sem isto o Eloquent pede ao Postgres
    // `RETURNING "id"` ao inserir, e a coluna nao existe; o MySQL so devolvia
    // um lastInsertId sem sentido e seguia.
    protected $primaryKey = null;
    public $incrementing = false;


    public function farmacia()
    {
        return $this->belongsTo(Farmacia::class, 'farmacia_id', 'farmacia_id');
    }
}
