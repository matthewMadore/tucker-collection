<?php

namespace App\Models;

use Database\Factories\ArtPieceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArtPiece extends Model
{
    /** @use HasFactory<ArtPieceFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'price_cents', 'status'];

    /**
     * @return HasMany<ArtPieceImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ArtPieceImage::class);
    }
}
