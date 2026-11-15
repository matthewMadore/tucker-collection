<?php

/**
 * Runs one real checkout in its own PHP process with its own database
 * connection, so ConcurrentCheckoutTest can race two of them for real.
 * Usage: php checkout_once.php <art piece id> <unix start time>
 * Prints "won" or "lost".
 */

use App\Exceptions\PieceUnavailableException;
use App\Models\ArtPiece;
use App\Services\CheckoutService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$piece = ArtPiece::findOrFail((int) $argv[1]);

// Both processes boot first, then start the checkout at the same moment.
time_sleep_until((float) $argv[2]);

try {
    $app->make(CheckoutService::class)->checkout($piece, 'racer@example.com', [
        'name' => 'Racer', 'line1' => '1 Main St', 'city' => 'Portland',
        'state' => 'OR', 'postal_code' => '97201', 'country' => 'US',
    ]);
    echo 'won';
} catch (PieceUnavailableException) {
    echo 'lost';
}
