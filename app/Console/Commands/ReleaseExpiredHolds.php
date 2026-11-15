<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled every minute (routes/console.php). Manual holds have no
 * held_until, so only checkout holds expire.
 */
#[Signature('app:release-expired-holds')]
#[Description('Cancel unpaid checkouts whose hold expired and put their pieces back on sale')]
class ReleaseExpiredHolds extends Command
{
    public function handle(CheckoutService $checkout): int
    {
        $orders = Order::with('payment')
            ->where('status', OrderStatus::Pending->value)
            ->whereHas('items', fn ($items) => $items
                ->whereNull('voided_at')
                ->whereHas('artPiece', fn ($piece) => $piece->where('status', 'held')->where('held_until', '<', now())))
            ->get();

        $released = 0;

        foreach ($orders as $order) {
            try {
                $checkout->releaseHold($order);
                $released++;
            } catch (Throwable $exception) {
                // Usually: the payment went through just before expiry. Leave
                // it for the webhook; report anything else and keep going.
                report($exception);
            }
        }

        $this->info("Released {$released} expired hold(s).");

        return self::SUCCESS;
    }
}
