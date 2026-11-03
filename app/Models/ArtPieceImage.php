<?php

namespace App\Models;

use Database\Factories\ArtPieceImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtPieceImage extends Model
{
    /** @use HasFactory<ArtPieceImageFactory> */
    use HasFactory;

    protected $fillable = ['art_piece_id', 'url', 'position'];

    /**
     * @return BelongsTo<ArtPiece, $this>
     */
    public function artPiece(): BelongsTo
    {
        return $this->belongsTo(ArtPiece::class);
    }
}
