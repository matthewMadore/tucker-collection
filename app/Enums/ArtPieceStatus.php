<?php

namespace App\Enums;

/**
 * Values match the status_valid CHECK constraint on art_pieces.
 */
enum ArtPieceStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Held = 'held';
    case Sold = 'sold';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Published => __('Published'),
            self::Held => __('On hold'),
            self::Sold => __('Sold'),
        };
    }

    /**
     * Whether visitors can see a piece with this status in the public gallery.
     * Held and sold pieces stay visible so the gallery shows the full body of work.
     */
    public function isPublic(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * @return list<self>
     */
    public static function publicCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isPublic()));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], self::cases());
    }
}
