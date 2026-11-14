<?php

use App\Enums\ArtPieceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;

/**
 * Every from → to pair for an enum, including self-transitions. A pair not
 * listed in $allowed must be rejected, so a new edge can't slip in silently.
 *
 * @param  class-string<UnitEnum>  $enum
 * @param  list<array{0: string, 1: string}>  $allowed
 * @return array<string, array{0: UnitEnum, 1: UnitEnum, 2: bool}>
 */
function transitionMatrix(string $enum, array $allowed): array
{
    $cases = [];

    foreach ($enum::cases() as $from) {
        foreach ($enum::cases() as $to) {
            $cases["{$from->value} → {$to->value}"] = [$from, $to, in_array([$from->value, $to->value], $allowed, true)];
        }
    }

    return $cases;
}

test('art piece status transitions', function (ArtPieceStatus $from, ArtPieceStatus $to, bool $isAllowed) {
    expect($from->canTransitionTo($to))->toBe($isAllowed);
})->with(transitionMatrix(ArtPieceStatus::class, [
    ['draft', 'published'], ['draft', 'sold'],
    ['published', 'draft'], ['published', 'held'], ['published', 'sold'],
    ['held', 'published'], ['held', 'sold'],
    ['sold', 'published'],
]));

test('payment status transitions', function (PaymentStatus $from, PaymentStatus $to, bool $isAllowed) {
    expect($from->canTransitionTo($to))->toBe($isAllowed);
})->with(transitionMatrix(PaymentStatus::class, [
    ['pending', 'processing'], ['pending', 'failed'],
    ['processing', 'succeeded'], ['processing', 'failed'],
    ['succeeded', 'refunded'], ['succeeded', 'disputed'],
    ['disputed', 'refunded'], ['disputed', 'succeeded'],
]));

test('order status transitions', function (OrderStatus $from, OrderStatus $to, bool $isAllowed) {
    expect($from->canTransitionTo($to))->toBe($isAllowed);
})->with(transitionMatrix(OrderStatus::class, [
    ['pending', 'paid'], ['pending', 'cancelled'],
    ['paid', 'fulfilled'], ['paid', 'refunded'],
    ['fulfilled', 'delivered'], ['fulfilled', 'refunded'],
    ['delivered', 'refunded'],
]));
