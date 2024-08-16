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

    
    public function farmacia()
    {
        return $this->belongsTo(Farmacia::class, 'farmacia_id', 'farmacia_id');
    }
}
