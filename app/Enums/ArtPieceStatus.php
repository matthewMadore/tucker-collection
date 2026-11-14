<?php

namespace App\Enums;

use App\Concerns\HasStatusTransitions;

/**
 * Values match the status_valid CHECK constraint on art_pieces.
 */
enum ArtPieceStatus: string
{
    use HasStatusTransitions;

    case Draft = 'draft';
    case Published = 'published';
    case Held = 'held';
    case Sold = 'sold';

    /**
     * Draft → Sold and Published → Sold cover offline sales. Sold → Published
     * is the only way out of Sold: relisting after a refund of an unshipped
     * order.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Sold],
            self::Published => [self::Draft, self::Held, self::Sold],
            self::Held => [self::Published, self::Sold],
            self::Sold => [self::Published],
        };
    }

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
