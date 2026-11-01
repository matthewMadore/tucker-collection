<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtPieceImage extends Model
{
    protected $fillable = ['art_piece_id', 'url', 'position'];

    public function artPiece()
    {
        return $this->belongsTo(ArtPiece::class);
    }
}
