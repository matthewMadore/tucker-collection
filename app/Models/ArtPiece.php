<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtPiece extends Model
{
    protected $fillable = ['name', 'description', 'price_cents', 'status'];

    public function images()
    {
        return $this->hasMany(ArtPieceImage::class);
    }
}
