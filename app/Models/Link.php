<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Link extends Model
{
    use HasFactory;

    protected $table = 'links';

    public function farmacia()
    {
        return $this->belongsTo(Farmacia::class, 'farmacia_id', 'farmacia_id');
    }
}