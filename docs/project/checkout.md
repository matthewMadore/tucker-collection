# Feature: Checkout and Payments

Guest checkout for a single piece, ported from the Fable version
(`tucker-collection-fable-archive`) with fewer moving parts. Buyers don't
need an account; the order stores their email.

**Status (2026-11-14):** checkout and the payment webhook work end to end
on the fake gateway (verified over real HTTP: checkout → signed webhook →
order paid, piece sold). Not built yet: hold expiry, the real Stripe
gateway, UPS shipping rates, the card form, emails, and admin orders. See
[Roadmap](#roadmap).

## Checkout call chain

`POST /art/{artPiece}/checkout` with `email` and `shipping_address`. No
login; rate-limited. Returns JSON for the card form (not a page visit).

| #   | Location                                                                           | Responsibility                                                                                                                                                                 |
| --- | ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | [routes/web.php:11](../../routes/web.php#L11)                                      | Public route with `throttle:checkout`: 10 attempts per minute per IP ([AppServiceProvider.php:34](../../app/Providers/AppServiceProvider.php#L34)), against card-testing bots. |
| 2   | [CheckoutRequest.php](../../app/Http/Requests/CheckoutRequest.php)                 | Validates email and shipping address. Has no amount field by design.                                                                                                           |
| 3   | [CheckoutController.php:19](../../app/Http/Controllers/CheckoutController.php#L19) | Drafts return 404, as on the public piece page.                                                                                                                                |
| 4   | [CheckoutService.php:33](../../app/Services/CheckoutService.php#L33)               | One transaction for steps 5–8: any failure rolls everything back and the piece stays published.                                                                                |
| 5   | [CheckoutService.php:37](../../app/Services/CheckoutService.php#L37)               | **The hold:** `UPDATE art_pieces SET status = 'held', held_until = now() + 20 min WHERE id = ? AND status = 'published'`. 0 rows → 409 "This piece is no longer available."    |
| 6   | [CheckoutService.php:51](../../app/Services/CheckoutService.php#L51)               | Pending order with prices read from the piece row, plus an order item snapshotting name and price.                                                                             |
| 7   | [CheckoutService.php:66](../../app/Services/CheckoutService.php#L66)               | `PaymentGateway::createIntent($order)`: the amount comes from the order.                                                                                                       |
| 8   | [CheckoutService.php:68](../../app/Services/CheckoutService.php#L68)               | Pending payment row with the gateway's intent id.                                                                                                                              |
| 9   | Response                                                                           | `201 { order_id, total_cents, currency, client_secret }`. The client secret goes to the browser's card form and is never stored.                                               |

## Webhook call chain: payment succeeded

Stripe calls `POST /stripe/webhook` when a payment completes. The webhook,
not the buyer's browser, decides whether an order is paid: it's signed,
retried by Stripe, and arrives even if the buyer closes the tab.

| #   | Location                                                                                           | Responsibility                                                                                              |
| --- | -------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| 1   | [routes/web.php:14](../../routes/web.php#L14), [bootstrap/app.php:21](../../bootstrap/app.php#L21) | Public route, excluded from CSRF (Stripe can't send a token; the signature replaces it).                    |
| 2   | [StripeWebhookController.php:21](../../app/Http/Controllers/StripeWebhookController.php#L21)       | No `STRIPE_WEBHOOK_SECRET` → 500 and log. An empty secret would let anyone sign a fake event.               |
| 3   | [StripeWebhookController.php:28](../../app/Http/Controllers/StripeWebhookController.php#L28)       | `Stripe\Webhook::constructEvent()` checks the HMAC signature and rejects events older than 5 minutes → 400. |
| 4   | [StripeWebhookHandler.php:29](../../app/Services/StripeWebhookHandler.php#L29)                     | One transaction for steps 5–8.                                                                              |
| 5   | [StripeWebhookHandler.php:30](../../app/Services/StripeWebhookHandler.php#L30)                     | Record the event id in `stripe_events_processed`; already there → skip (Stripe delivers at least once).     |
| 6   | [StripeWebhookHandler.php:63](../../app/Services/StripeWebhookHandler.php#L63)                     | Payment already succeeded (same outcome under a new event id) → skip; statuses never move backwards.        |
| 7   | [StripeWebhookHandler.php:72](../../app/Services/StripeWebhookHandler.php#L72)                     | Amount or currency differs from the payment → log an error and leave the order unpaid for review.           |
| 8   | [StripeWebhookHandler.php:89](../../app/Services/StripeWebhookHandler.php#L89)                     | Payment → processing → succeeded, order → paid (`placed_at`), piece → sold (`held_until` cleared).          |

Unknown payment intents and event types the app doesn't handle are
acknowledged (200) so Stripe stops retrying; unknown intents are logged.

**Differences from Fable:**

- **Recording and applying are one transaction.** Fable inserted the event
  id first, outside the transaction, then applied it in a queued job. If
  applying failed, the id was already recorded, so every retry was skipped
  and the payment was silently lost. Here a failure rolls back both, the
  webhook returns 500, and Stripe's retry applies it.
- **Handled in the request, not a queue.** It's a few queries, well
  within Stripe's timeout, and it removes the need for a queue worker in
  production. Stripe's retries give the same failure recovery a queue
  would.
- **Signature check from the Stripe SDK** (`stripe/stripe-php`) instead of
  Fable's hand-written one, and no simulated webhook job: locally, the
  real Stripe test mode and `stripe listen` replace it.

## Fraud and correctness controls

| Control                                       | Where                                                                    | Proven by                                      |
| --------------------------------------------- | ------------------------------------------------------------------------ | ---------------------------------------------- |
| A piece can't be sold twice                   | Conditional UPDATE (the hold) + partial unique index on live order items | `ConcurrentCheckoutTest`, `CheckoutSchemaTest` |
| Price can't be tampered with                  | Request has no amount; service reads the piece row                       | `CheckoutTest`                                 |
| Card-testing bots are slowed down             | `throttle:checkout`, 10/min per IP                                       | `CheckoutTest`                                 |
| Partial failures leave no half-made order     | Single DB transaction around hold, order, and gateway call               | `CheckoutTest` (gateway throws)                |
| Drafts aren't discoverable                    | 404 for non-public pieces                                                | `CheckoutTest`                                 |
| Forged "payment succeeded" calls are rejected | SDK signature check; webhook refused if no secret is configured          | `StripeWebhookTest`                            |
| Captured webhooks can't be replayed later     | Signature timestamp older than 5 minutes is rejected                     | `StripeWebhookTest`                            |
| Each Stripe event is applied once             | `stripe_events_processed` ledger, in the same transaction as the changes | `StripeWebhookTest`                            |
| Paid amount must match the order              | Amount + currency check before marking paid                              | `StripeWebhookTest`                            |
| Illegal status changes are impossible         | Enum state machines + `transitionTo()`, CHECK constraints                | `StatusTransitionsTest`, `CheckoutSchemaTest`  |

## The payment gateway seam

[PaymentGateway](../../app/Contracts/PaymentGateway.php) is the only
interface the app uses to talk to Stripe. Gateways return a
[PaymentIntent](../../app/Gateways/PaymentIntent.php) (id + client secret),
so no Stripe SDK type leaks into the app.
[FakeStripePaymentGateway](../../app/Gateways/FakeStripePaymentGateway.php)
is bound in `AppServiceProvider` for local development and tests; the real
Stripe gateway replaces it when keys are configured.

## Tests

- [StripeWebhookTest.php](../../tests/Feature/StripeWebhookTest.php):
  success marks payment/order/piece; wrong-secret, unsigned and
  5-minute-old signatures → 400; no configured secret → 500; a redelivered
  event applies once; a second success event can't change a paid order;
  amount or currency mismatch is flagged, not paid; unknown intents and
  event types are acknowledged; a failed attempt records nothing and the
  retry succeeds. Each protection was removed in turn to confirm its test
  fails without it.

- [CheckoutTest.php](../../tests/Feature/CheckoutTest.php): guest
  checkout creates the hold (20 min), order, item snapshot and payment;
  client-supplied amounts are ignored; held/sold pieces → 409 with no
  order; drafts → 404; validation; gateway failure rolls back; 429 after
  10 attempts.
- [ConcurrentCheckoutTest.php](../../tests/Integration/ConcurrentCheckoutTest.php):
  two separate PHP processes run the real `CheckoutService` against
  Postgres at the same instant; exactly one wins. It lives in the
  `Integration` suite, which uses `DatabaseTruncation` instead of
  `RefreshDatabase`: each process needs committed data, and
  RefreshDatabase keeps a test's data in an uncommitted transaction.
  Verified to fail if the hold is replaced with a read-then-update.

## Roadmap

| Commit | Adds                                                                                 |
| ------ | ------------------------------------------------------------------------------------ |
| 5      | Hold expiry: release unpaid holds after 20 minutes                                   |
| 6      | Real Stripe gateway (SDK already installed for the webhook)                          |
| 7–8    | UPS shipping rates (fake, then the real UPS Rating API); shipping added to the total |
| 9      | Buy button and card form (`@stripe/stripe-js`)                                       |
| 10     | Order emails and a signed "view your order" link                                     |
| 11     | Admin orders: mark shipped, refund (calls Stripe, unlike Fable's)                    |
| 12     | Disputes and dashboard refunds via webhooks                                          |
