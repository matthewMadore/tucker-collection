# Feature: Checkout and Payments

Guest checkout for a single piece, ported from the Fable version
(`tucker-collection-fable-archive`) with fewer moving parts. Buyers don't
need an account; the order stores their email.

**Status (2026-11-14):** checkout backend implemented on a fake payment
gateway. Not built yet: the webhook that marks orders paid, hold expiry,
real Stripe, UPS shipping rates, the card form, emails, and admin orders.
See [Roadmap](#roadmap).

## Checkout call chain

`POST /art/{artPiece}/checkout` with `email` and `shipping_address`. No
login; rate-limited. Returns JSON for the card form (not a page visit).

| #   | Location                                                                           | Responsibility                                                                                                                                                                 |
| --- | ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | [routes/web.php:10](../../routes/web.php#L10)                                      | Public route with `throttle:checkout`: 10 attempts per minute per IP ([AppServiceProvider.php:34](../../app/Providers/AppServiceProvider.php#L34)), against card-testing bots. |
| 2   | [CheckoutRequest.php](../../app/Http/Requests/CheckoutRequest.php)                 | Validates email and shipping address. Has no amount field by design.                                                                                                           |
| 3   | [CheckoutController.php:19](../../app/Http/Controllers/CheckoutController.php#L19) | Drafts return 404, as on the public piece page.                                                                                                                                |
| 4   | [CheckoutService.php:33](../../app/Services/CheckoutService.php#L33)               | One transaction for steps 5–8: any failure rolls everything back and the piece stays published.                                                                                |
| 5   | [CheckoutService.php:37](../../app/Services/CheckoutService.php#L37)               | **The hold:** `UPDATE art_pieces SET status = 'held', held_until = now() + 20 min WHERE id = ? AND status = 'published'`. 0 rows → 409 "This piece is no longer available."    |
| 6   | [CheckoutService.php:51](../../app/Services/CheckoutService.php#L51)               | Pending order with prices read from the piece row, plus an order item snapshotting name and price.                                                                             |
| 7   | [CheckoutService.php:66](../../app/Services/CheckoutService.php#L66)               | `PaymentGateway::createIntent($order)`: the amount comes from the order.                                                                                                       |
| 8   | [CheckoutService.php:68](../../app/Services/CheckoutService.php#L68)               | Pending payment row with the gateway's intent id.                                                                                                                              |
| 9   | Response                                                                           | `201 { order_id, total_cents, currency, client_secret }`. The client secret goes to the browser's card form and is never stored.                                               |

## Fraud and correctness controls

| Control                                   | Where                                                                    | Proven by                                      |
| ----------------------------------------- | ------------------------------------------------------------------------ | ---------------------------------------------- |
| A piece can't be sold twice               | Conditional UPDATE (the hold) + partial unique index on live order items | `ConcurrentCheckoutTest`, `CheckoutSchemaTest` |
| Price can't be tampered with              | Request has no amount; service reads the piece row                       | `CheckoutTest`                                 |
| Card-testing bots are slowed down         | `throttle:checkout`, 10/min per IP                                       | `CheckoutTest`                                 |
| Partial failures leave no half-made order | Single DB transaction around hold, order, and gateway call               | `CheckoutTest` (gateway throws)                |
| Drafts aren't discoverable                | 404 for non-public pieces                                                | `CheckoutTest`                                 |
| Illegal status changes are impossible     | Enum state machines + `transitionTo()`, CHECK constraints                | `StatusTransitionsTest`, `CheckoutSchemaTest`  |

## The payment gateway seam

[PaymentGateway](../../app/Contracts/PaymentGateway.php) is the only
interface the app uses to talk to Stripe. Gateways return a
[PaymentIntent](../../app/Gateways/PaymentIntent.php) (id + client secret),
so no Stripe SDK type leaks into the app.
[FakeStripePaymentGateway](../../app/Gateways/FakeStripePaymentGateway.php)
is bound in `AppServiceProvider` for local development and tests; the real
Stripe gateway replaces it when keys are configured.

## Tests

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

| Commit | Adds                                                                                      |
| ------ | ----------------------------------------------------------------------------------------- |
| 4      | Stripe webhook: signature check, idempotency ledger, amount check, order paid, piece sold |
| 5      | Hold expiry: release unpaid holds after 20 minutes                                        |
| 6      | Real Stripe gateway (`stripe/stripe-php`)                                                 |
| 7–8    | UPS shipping rates (fake, then the real UPS Rating API); shipping added to the total      |
| 9      | Buy button and card form (`@stripe/stripe-js`)                                            |
| 10     | Order emails and a signed "view your order" link                                          |
| 11     | Admin orders: mark shipped, refund (calls Stripe, unlike Fable's)                         |
| 12     | Disputes and dashboard refunds via webhooks                                               |
