<?php

namespace App\Models;

use App\Enums\ArtPieceStatus;
use Database\Factories\ArtPieceFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ArtPieceStatus $status
 */
class ArtPiece extends Model
{
    /** @use HasFactory<ArtPieceFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'price_cents', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ArtPieceStatus::class,
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
     * The only way status should change: illegal moves throw instead of saving.
     *
     * @throws DomainException
     */
    public function transitionTo(ArtPieceStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new DomainException("Cannot change an art piece from {$this->status->value} to {$to->value}.");
        }

        $this->update(['status' => $to]);
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
