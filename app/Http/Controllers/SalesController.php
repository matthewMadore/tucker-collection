<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Requests\ShipOrderRequest;
use App\Models\Order;
use App\Services\OrderService;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff view of paid orders: ship them, refund them. Orders that never got
 * paid (pending or cancelled) aren't sales and aren't listed.
 */
class SalesController extends Controller
{
    public function index(): Response
    {
        $orders = Order::with(['items', 'shipment'])
            ->whereNotIn('status', [OrderStatus::Pending, OrderStatus::Cancelled])
            ->latest('placed_at')
            ->paginate(20)
            ->through(fn (Order $order) => [
                'id' => $order->id,
                'status' => $order->status->value,
                'placed_at' => $order->placed_at?->toIso8601String(),
                'piece_name' => $order->items->first()?->name_snapshot,
                'total_cents' => $order->total_cents,
                'shipping_cents' => $order->shipping_cents,
                'buyer_email' => $order->buyer_email,
                'shipping_address' => $order->shipping_address,
                'service_level' => $order->shipment?->service_level,
                'tracking_number' => $order->shipment?->tracking_number,
                'can_ship' => $order->status->canTransitionTo(OrderStatus::Fulfilled),
                'can_refund' => $order->status->canTransitionTo(OrderStatus::Refunded),
            ]);

        return Inertia::render('sales/Index', ['orders' => $orders]);
    }

    public function ship(ShipOrderRequest $request, Order $order, OrderService $orders): RedirectResponse
    {
        return $this->attempt(
            fn () => $orders->ship($order, $request->validated('tracking_number')),
            'Marked shipped. The buyer has been emailed the tracking number.',
        );
    }

    public function refund(Order $order, OrderService $orders): RedirectResponse
    {
        return $this->attempt(
            fn () => $orders->refund($order),
            'Refunded. The buyer has been emailed.',
        );
    }

    /**
     * A refused move (e.g. a second click after the order already shipped)
     * comes back as an error toast instead of an error page.
     */
    private function attempt(Closure $action, string $success): RedirectResponse
    {
        try {
            $action();
            Inertia::flash('toast', ['type' => 'success', 'message' => $success]);
        } catch (DomainException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'That order has changed since this page loaded. Nothing was done.']);
        }

        return redirect()->back();
    }
}
