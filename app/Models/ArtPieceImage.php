<?php

namespace App\Models;

use Database\Factories\ArtPieceImageFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ArtPieceImage extends Model
{
    /** @use HasFactory<ArtPieceImageFactory> */
    use HasFactory;

    /**
     * The disk uploaded art piece images are stored on.
     */
    public const string DISK = 'public';

    protected $fillable = ['art_piece_id', 'path', 'position'];

    /**
     * @return BelongsTo<ArtPiece, $this>
     */
    public function artPiece(): BelongsTo
    {
        return $this->belongsTo(ArtPiece::class);
    }

    /**
     * The public URL for the stored file, built from its path on the disk.
     *
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk(self::DISK)->url($this->path));
    }
}
