<?php

namespace App\Http\Controllers;

use App\Services\StripeWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookHandler $handler): JsonResponse
    {
        $secret = (string) config('services.stripe.webhook_secret');

        // Without a secret, anyone could sign a fake "payment succeeded".
        // Fail loudly; Stripe keeps retrying until it's configured.
        if ($secret === '') {
            Log::error('STRIPE_WEBHOOK_SECRET is not set; refusing Stripe webhooks.');

            return response()->json(['message' => 'Webhook not configured.'], 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['message' => 'Invalid webhook.'], 400);
        }

        $handler->handle($event->id, $event->type, $event->data->object->toArray());

        return response()->json(['received' => true]);
    }
}
