<?php

use App\Enums\ArtPieceStatus;
use App\Models\ArtPiece;
use App\Models\Order;
use Symfony\Component\Process\Process;

// Truncation only clears data before each test; clear it after too, so the
// committed rows don't leak into suites that run later in the same process.
afterEach(fn () => $this->truncateDatabaseTables());

test('two buyers checking out the same piece at the same moment: exactly one wins', function () {
    $piece = ArtPiece::factory()->create(['status' => 'published']);
    $startAt = microtime(true) + 2;

    $buyers = collect([1, 2])->map(function () use ($piece, $startAt) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/checkout_once.php', (string) $piece->id, (string) $startAt],
            env: [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => config('database.default'),
                'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
            ],
        );
        $process->start();

        return $process;
    });

    $results = $buyers->map(function (Process $process) {
        $process->wait();

        return trim($process->getOutput()) ?: $process->getErrorOutput();
    })->sort()->values()->all();

    expect($results)->toBe(['lost', 'won'])
        ->and(Order::count())->toBe(1)
        ->and($piece->fresh()->status)->toBe(ArtPieceStatus::Held);
});
