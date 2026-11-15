<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The buyer's view of their order. Guests have no account, so access is by
 * the signed link in their email (the route's "signed" middleware).
 */
class OrderController extends Controller
{
    public function show(Order $order): Response
    {
        $order->load('items');

        return Inertia::render('gallery/Order', [
            'order' => [
                'status' => $order->status->value,
                'piece_name' => $order->items->first()?->name_snapshot,
                'subtotal_cents' => $order->subtotal_cents,
                'shipping_cents' => $order->shipping_cents,
                'total_cents' => $order->total_cents,
                'buyer_email' => $order->buyer_email,
                'shipping_address' => $order->shipping_address,
            ],
        ]);
    }
}
