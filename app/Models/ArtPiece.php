<?php

namespace App\Models;

use App\Concerns\TransitionsStatus;
use App\Enums\ArtPieceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ArtPieceFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ArtPieceStatus $status
 * @property ?CarbonImmutable $held_until
 */
class ArtPiece extends Model
{
    /** @use HasFactory<ArtPieceFactory> */
    use HasFactory, TransitionsStatus;

    protected $fillable = ['name', 'description', 'price_cents', 'currency', 'status', 'held_until'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ArtPieceStatus::class,
            'held_until' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ArtPieceImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ArtPieceImage::class);
    }

    /**
     * Pieces visitors can see in the public gallery (everything but drafts).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleToPublic(Builder $query): void
    {
        $query->whereIn('status', ArtPieceStatus::publicCases());
    }
}
