# Feature: Checkout and Payments

Guest checkout for a single piece, ported from the Fable version
(`tucker-collection-fable-archive`) with fewer moving parts. Buyers don't
need an account; the order stores their email.

**Status (2026-11-14):** buyers can check out from a piece's public page:
shipping form, server-quoted UPS shipping, total, Stripe card form,
confirmation page. Checkout, the webhook and hold expiry are verified end
to end in the running app on the fake/flat-rate providers. The real
Stripe gateway, Stripe's card form and the live UPS provider switch on
when their keys are set; they're tested against recorded or faked HTTP,
not yet against live Stripe test mode or the UPS sandbox. Not built yet:
emails and admin orders. See [Roadmap](#roadmap).

## Checkout call chain

`POST /art/{artPiece}/checkout` with `email` and `shipping_address`. No
login; rate-limited. Returns JSON for the card form (not a page visit).

| #   | Location                                                                           | Responsibility                                                                                                                                                                                                                                                      |
| --- | ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | [routes/web.php:11](../../routes/web.php#L11)                                      | Public route with `throttle:checkout`: 10 attempts per minute per IP ([AppServiceProvider.php:49](../../app/Providers/AppServiceProvider.php#L49)), against card-testing bots.                                                                                      |
| 2   | [CheckoutRequest.php](../../app/Http/Requests/CheckoutRequest.php)                 | Validates email and shipping address. Has no amount field by design.                                                                                                                                                                                                |
| 3   | [CheckoutController.php:23](../../app/Http/Controllers/CheckoutController.php#L23) | Drafts return 404, as on the public piece page.                                                                                                                                                                                                                     |
| 4   | [CheckoutService.php:40](../../app/Services/CheckoutService.php#L40)               | One transaction for steps 5–9: any failure rolls everything back and the piece stays published.                                                                                                                                                                     |
| 5   | [CheckoutService.php:44](../../app/Services/CheckoutService.php#L44)               | **The hold:** `UPDATE art_pieces SET status = 'held', held_until = now() + 20 min WHERE id = ? AND status = 'published'`. 0 rows → 409 "This piece is no longer available."                                                                                         |
| 6   | [CheckoutService.php:59](../../app/Services/CheckoutService.php#L59)               | **Shipping quote** from the `ShippingRateProvider` (UPS) for this piece and address, on the server.                                                                                                                                                                 |
| 7   | [CheckoutService.php:61](../../app/Services/CheckoutService.php#L61)               | Pending order: subtotal from the piece row, shipping from the quote, total = both. Plus an order item snapshotting name and price, and a pending shipment recording carrier, service level and quoted rate ([line 76](../../app/Services/CheckoutService.php#L76)). |
| 8   | [CheckoutService.php:82](../../app/Services/CheckoutService.php#L82)               | `PaymentGateway::createIntent($order)`: the amount is the order total.                                                                                                                                                                                              |
| 9   | [CheckoutService.php:84](../../app/Services/CheckoutService.php#L84)               | Pending payment row with the gateway's intent id.                                                                                                                                                                                                                   |
| 10  | Response                                                                           | `201 { subtotal_cents, shipping_cents, total_cents, shipping: { service_level, estimated_days }, client_secret }`: only what the card form uses. The client secret goes to the browser and is never stored.                                                         |

## Webhook call chain: payment succeeded

Stripe calls `POST /stripe/webhook` when a payment completes. The webhook,
not the buyer's browser, decides whether an order is paid: it's signed,
retried by Stripe, and arrives even if the buyer closes the tab.

| #   | Location                                                                                           | Responsibility                                                                                                                                                               |
| --- | -------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | [routes/web.php:15](../../routes/web.php#L15), [bootstrap/app.php:21](../../bootstrap/app.php#L21) | Public route, excluded from CSRF (Stripe can't send a token; the signature replaces it).                                                                                     |
| 2   | [StripeWebhookController.php:21](../../app/Http/Controllers/StripeWebhookController.php#L21)       | No `STRIPE_WEBHOOK_SECRET` → 500 and log. An empty secret would let anyone sign a fake event.                                                                                |
| 3   | [StripeWebhookController.php:28](../../app/Http/Controllers/StripeWebhookController.php#L28)       | `Stripe\Webhook::constructEvent()` checks the HMAC signature and rejects events older than 5 minutes → 400.                                                                  |
| 4   | [StripeWebhookHandler.php:30](../../app/Services/StripeWebhookHandler.php#L30)                     | One transaction for steps 5–9.                                                                                                                                               |
| 5   | [StripeWebhookHandler.php:31](../../app/Services/StripeWebhookHandler.php#L31)                     | Record the event id in `stripe_events_processed`; already there → skip (Stripe delivers at least once).                                                                      |
| 6   | [StripeWebhookHandler.php:64](../../app/Services/StripeWebhookHandler.php#L64)                     | Payment already succeeded (same outcome under a new event id) → skip; statuses never move backwards.                                                                         |
| 7   | [StripeWebhookHandler.php:69](../../app/Services/StripeWebhookHandler.php#L69)                     | Lock the order row. If it's no longer pending (its hold expired and it was cancelled), log **critical: refund it** and stop, instead of selling a piece that's back on sale. |
| 8   | [StripeWebhookHandler.php:91](../../app/Services/StripeWebhookHandler.php#L91)                     | Amount or currency differs from the payment → log an error and leave the order unpaid for review.                                                                            |
| 9   | [StripeWebhookHandler.php:108](../../app/Services/StripeWebhookHandler.php#L108)                   | Payment → processing → succeeded, order → paid (`placed_at`), piece → sold (`held_until` cleared).                                                                           |

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

## Buying: the piece page

What a buyer sees on a published piece's public page
([gallery/Show.vue:57](../../resources/js/pages/gallery/Show.vue#L57)).
Held and sold pieces show "reserved by another buyer" / "sold" instead.

| #   | Location                                                                           | What happens                                                                                                                                                                                                                                                                                                                                                                              |
| --- | ---------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | [BuyArtPiece.vue](../../resources/js/components/BuyArtPiece.vue)                   | **Buy this piece · $price** opens a shipping form (US addresses only, for now). Fields carry `autocomplete`/`inputmode` hints, so iPhone autofill and the numeric keypad work.                                                                                                                                                                                                            |
| 2   | [BuyArtPiece.vue:86](../../resources/js/components/BuyArtPiece.vue#L86)            | **Continue to payment** posts to checkout with Inertia's `useHttp`. Validation errors (422) appear under their fields. Any other failure, such as 409 "no longer available" or 429, is shown as a message, and the page reloads the piece's status ([line 90](../../resources/js/components/BuyArtPiece.vue#L90)), so a buyer who lost the race sees "reserved by another buyer".         |
| 3   | Same component                                                                     | Shows piece + UPS shipping (service and days) = total, and "reserved for you for 20 minutes".                                                                                                                                                                                                                                                                                             |
| 4   | [BuyArtPiece.vue:108](../../resources/js/components/BuyArtPiece.vue#L108)          | Loads Stripe.js with the **publishable** key (`STRIPE_KEY`, passed as a page prop by [GalleryController.php:55](../../app/Http/Controllers/GalleryController.php#L55); the secret key never reaches the browser) and mounts Stripe's Payment Element with the client secret. Without `STRIPE_KEY` it says card payments aren't set up, so the flow can be tried locally up to this point. |
| 5   | [BuyArtPiece.vue:128](../../resources/js/components/BuyArtPiece.vue#L128)          | **Pay $total** calls `stripe.confirmPayment()`. A declined card shows Stripe's message and the buyer can try another card while the hold lasts. On success Stripe redirects to `/checkout/complete`.                                                                                                                                                                                      |
| 6   | [CheckoutController.php:48](../../app/Http/Controllers/CheckoutController.php#L48) | The confirmation page looks the order up by the PaymentIntent id and shows **our** order status ("Thank you!" when paid, "Payment processing" while waiting for the webhook, "wasn't completed" if the hold ended). It ignores Stripe's `redirect_status` query parameter: only the webhook marks orders paid.                                                                            |

**Card payments need both Stripe keys:** `STRIPE_KEY` (publishable, for the
browser) and `STRIPE_SECRET` (for the server). With only the publishable
key, the fake gateway's client secret can't be used by Stripe.js.

## Hold expiry

A checkout holds the piece for 20 minutes
([`CheckoutService::HOLD_MINUTES`](../../app/Services/CheckoutService.php)).
If the buyer doesn't pay in time, the piece goes back on sale.

| #   | Location                                                                             | Responsibility                                                                                                                                                                                                             |
| --- | ------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | [routes/console.php:5](../../routes/console.php#L5)                                  | Laravel's scheduler runs `app:release-expired-holds` every minute (no overlapping runs).                                                                                                                                   |
| 2   | [ReleaseExpiredHolds.php:25](../../app/Console/Commands/ReleaseExpiredHolds.php#L25) | Finds pending orders with a live item whose piece is `held` with `held_until` in the past. Manual holds have no `held_until`, so they never expire.                                                                        |
| 3   | [CheckoutService.php:105](../../app/Services/CheckoutService.php#L105)               | **Cancel the payment at Stripe first**, so the buyer can't pay for a piece that's back on sale. If Stripe refuses (the payment already went through), it throws and nothing changes; the webhook will mark the order paid. |
| 4   | [CheckoutService.php:110](../../app/Services/CheckoutService.php#L110)               | Lock the order row so a webhook can't interleave; skip it if it's no longer pending.                                                                                                                                       |
| 5   | [CheckoutService.php:116](../../app/Services/CheckoutService.php#L116)               | Order → cancelled, payment → failed, item voided, piece → published (`held_until` cleared).                                                                                                                                |
| 6   | [ReleaseExpiredHolds.php:36](../../app/Console/Commands/ReleaseExpiredHolds.php#L36) | A failure on one order is reported and the rest are still released.                                                                                                                                                        |

**The scheduler must be running** for holds to expire: locally
`./vendor/bin/sail artisan schedule:work`; in production, the host's
scheduler (one cron entry or the platform's scheduler setting).

**Differences from Fable:** Fable released the piece but never cancelled
the Stripe payment (the buyer could still pay for a piece back on sale),
left the payment `pending`, and had no locking between expiry and the
webhook.

**Declined cards don't release the hold.** Stripe sends
`payment_intent.payment_failed` when a card is declined, but the buyer can
retry with another card on the same payment. Releasing on the first
decline would let someone else take the piece while they retype it, so the
hold stays until it expires. Card-testing bots are limited by the
checkout rate limit instead.

## Fraud and correctness controls

| Control                                       | Where                                                                                                 | Proven by                                      |
| --------------------------------------------- | ----------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| A piece can't be sold twice                   | Conditional UPDATE (the hold) + partial unique index on live order items                              | `ConcurrentCheckoutTest`, `CheckoutSchemaTest` |
| Price can't be tampered with                  | Request has no amount; service reads the piece row                                                    | `CheckoutTest`                                 |
| Card-testing bots are slowed down             | `throttle:checkout`, 10/min per IP                                                                    | `CheckoutTest`                                 |
| Partial failures leave no half-made order     | Single DB transaction around hold, order, and gateway call                                            | `CheckoutTest` (gateway throws)                |
| Drafts aren't discoverable                    | 404 for non-public pieces                                                                             | `CheckoutTest`                                 |
| Forged "payment succeeded" calls are rejected | SDK signature check; webhook refused if no secret is configured                                       | `StripeWebhookTest`                            |
| Captured webhooks can't be replayed later     | Signature timestamp older than 5 minutes is rejected                                                  | `StripeWebhookTest`                            |
| Each Stripe event is applied once             | `stripe_events_processed` ledger, in the same transaction as the changes                              | `StripeWebhookTest`                            |
| Paid amount must match the order              | Amount + currency check before marking paid                                                           | `StripeWebhookTest`                            |
| Unpaid holds don't block a piece forever      | Scheduled expiry after 20 minutes                                                                     | `HoldExpiryTest`                               |
| No payment for a piece that's back on sale    | Expiry cancels the Stripe payment before releasing; order row locks; late payments flagged for refund | `HoldExpiryTest`, `StripeWebhookTest`          |
| Illegal status changes are impossible         | Enum state machines + `transitionTo()`, CHECK constraints                                             | `StatusTransitionsTest`, `CheckoutSchemaTest`  |

## The payment gateway seam

[PaymentGateway](../../app/Contracts/PaymentGateway.php) is the only
interface the app uses to talk to Stripe: `createIntent()` and
`cancelIntent()`. Gateways return a
[PaymentIntent](../../app/Gateways/PaymentIntent.php) (id + client secret),
so no Stripe SDK type leaks into the app.

| Gateway                                                                     | Used when                                                                                            | Behavior                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| --------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [StripePaymentGateway](../../app/Gateways/StripePaymentGateway.php)         | `STRIPE_SECRET` is set ([AppServiceProvider.php:30](../../app/Providers/AppServiceProvider.php#L30)) | Creates a PaymentIntent for the order total with `order_id` metadata and `receipt_email` (Stripe emails the buyer a receipt). Sends an idempotency key per order ([line 30](../../app/Gateways/StripePaymentGateway.php#L30)), so a retried request can't create a second payment. `cancelIntent()` treats "already cancelled" as success and throws on anything else, such as "already succeeded" ([line 43](../../app/Gateways/StripePaymentGateway.php#L43)), which keeps the hold. |
| [FakeStripePaymentGateway](../../app/Gateways/FakeStripePaymentGateway.php) | `STRIPE_SECRET` is empty                                                                             | Returns fake ids; cancelling does nothing. Local development works without a Stripe account.                                                                                                                                                                                                                                                                                                                                                                                           |

`phpunit.xml` forces `STRIPE_SECRET` to empty, so the test suite never
calls Stripe, even when `.env` has real keys.

## Shipping rates

[ShippingRateProvider](../../app/Contracts/ShippingRateProvider.php) is the
seam to the carrier, like `PaymentGateway` is to Stripe. Checkout asks it
for a quote on the server, so the shipping cost (like the price) can't be
set by the browser. It receives the whole art piece, not just its price,
because real carrier rates need the package's size and weight.

| Provider                                                                    | Used when                                                                                            | Behavior                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| --------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [UpsShippingRateProvider](../../app/Gateways/UpsShippingRateProvider.php)   | `UPS_CLIENT_ID` is set ([AppServiceProvider.php:36](../../app/Providers/AppServiceProvider.php#L36)) | Live UPS Ground rate from the Rating API (`/api/rating/v2409/Rate`, [line 61](../../app/Gateways/UpsShippingRateProvider.php#L61)): signature required (package-level `DCISType "2"`, [line 81](../../app/Gateways/UpsShippingRateProvider.php#L81)) and the piece's price as declared value. Charges the account's negotiated rate when UPS returns one ([line 92](../../app/Gateways/UpsShippingRateProvider.php#L92)). The OAuth token is cached until a minute before it expires ([line 123](../../app/Gateways/UpsShippingRateProvider.php#L123)). **If UPS fails** (error response, outage, unexpected response), it charges the flat rate and logs a warning ([line 37](../../app/Gateways/UpsShippingRateProvider.php#L37)), so a UPS problem never blocks a sale. |
| [FlatRateShippingProvider](../../app/Gateways/FlatRateShippingProvider.php) | `UPS_CLIENT_ID` is empty, or as the UPS fallback                                                     | $45 UPS Ground (signature required) plus $5 of declared-value insurance per full $1,000 of the piece's price, 5 days.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |

`phpunit.xml` forces `UPS_CLIENT_ID` to empty, so tests never call UPS.

**Setup** (`.env`): `UPS_CLIENT_ID`, `UPS_CLIENT_SECRET` (an app at
developer.ups.com with the Rating and OAuth APIs), `UPS_ACCOUNT_NUMBER`
(6-character shipper number), and the ship-from address
(`UPS_SHIP_FROM_LINE1`, `_CITY`, `_STATE`, `_POSTAL_CODE`). `UPS_BASE_URL`
defaults to the sandbox, `https://wwwcie.ups.com`; set it to
`https://onlinetools.ups.com` for production.

**Package size:** pieces don't store dimensions or weight yet, so every
piece is rated as one default box, 24 × 20 × 4 in, 8 lb
(`UPS_PACKAGE_LENGTH`/`_WIDTH`/`_HEIGHT`/`_WEIGHT`). Per-piece size and
weight is a later change to the upload form.

**Built from UPS's published OpenAPI spec** (UPS-API/api-documentation on
GitHub) and tested with faked HTTP responses, not yet against the UPS
sandbox. Check these in the sandbox once credentials exist, because the
spec itself is ambiguous about them:

- `Request.RequestOption` is listed as required but never defined; the
  request omits it, as UPS's own examples do.
- `DeclaredValue.MonetaryValue` is declared as exactly 8 characters, while
  UPS's examples use values like `"100.00"`.
- `DCISType "2"` means "signature required" at package level but "adult
  signature" at shipment level; the request uses package level.
- Declared value is capped by UPS at $5,000 (local); pieces above that
  need a different insurance approach.

**Differences from Fable:** Fable had a shipping-quote endpoint but its
checkout always set `shipping_cents = 0`, so shipping was never charged,
and it had no real UPS client, only a fake one. Here the quote is part of
checkout and included in the payment amount, there is no separate quote
endpoint, and live UPS rates are implemented.

## Running locally with Stripe test mode

Test mode uses fake cards and never moves money.

1. Create a Stripe account; in the dashboard, switch to **Test mode** and
   copy the secret key (`sk_test_...`) into `.env` as `STRIPE_SECRET`.
2. Install the Stripe CLI (`brew install stripe/stripe-cli/stripe`), run
   `stripe login`, then forward webhooks to the app:
   `stripe listen --forward-to localhost/stripe/webhook`. It prints a
   signing secret (`whsec_...`): put it in `.env` as
   `STRIPE_WEBHOOK_SECRET`.
3. Keep `./vendor/bin/sail artisan schedule:work` running so expired holds
   are released.

Until the card form exists (commit 9), a payment can be completed from
the Stripe dashboard or with `stripe payment_intents confirm <pi_id>
--payment-method=pm_card_visa`; the webhook then marks the order paid.

In production, the webhook signing secret comes from the endpoint added
in the Stripe dashboard (Developers → Webhooks) instead of `stripe listen`.

## Tests

- Buy flow: [CheckoutTest.php](../../tests/Feature/CheckoutTest.php)
  covers the confirmation page (it shows the order's real status for
  paid, pending and cancelled orders even when the redirect claims
  success; unknown payments → 404), and
  [GalleryTest.php](../../tests/Feature/GalleryTest.php) checks the piece
  page passes the publishable key and never the secret key. In a browser
  (iPhone and desktop): two buyers compete for one piece; the first sees
  $100 + $45 = $145 and is reserved, the second is told it's no longer
  available and the Buy button disappears; the confirmation page shows
  "Payment processing" and then "Thank you!" once paid.

- [UpsShippingRateProviderTest.php](../../tests/Feature/UpsShippingRateProviderTest.php):
  with UPS faked over HTTP, a quote logs in with Basic auth, sends a
  Ground, signature-required, declared-value request from the shipper
  account to the buyer's address, and returns the rate; the negotiated
  rate wins; the token is reused; UPS errors, outages and unexpected
  responses fall back to the flat rate with a warning; live UPS is used
  only when credentials are set.

- [CheckoutTest.php](../../tests/Feature/CheckoutTest.php) also covers
  shipping: the total is price + quoted shipping, a pending shipment
  records the quote, a request can't set the shipping cost, and the fake
  UPS insurance rule ($5 per full $1,000).

- [StripePaymentGatewayTest.php](../../tests/Feature/StripePaymentGatewayTest.php):
  runs the real Stripe SDK over a recording HTTP client. Creating a
  payment sends the total, currency, metadata, receipt email and
  idempotency key; cancelling hits the cancel endpoint; an already
  cancelled payment is fine; an already succeeded one throws; the real
  gateway is used only when a secret key is set.

- [HoldExpiryTest.php](../../tests/Feature/HoldExpiryTest.php): an
  expired hold cancels the payment at Stripe, cancels the order, fails the
  payment, voids the item and republishes the piece; unexpired and manual
  holds are left alone; if Stripe refuses to cancel, that hold is kept and
  the others are still released; the command is scheduled every minute.

- [StripeWebhookTest.php](../../tests/Feature/StripeWebhookTest.php):
  success marks payment/order/piece; wrong-secret, unsigned and
  5-minute-old signatures → 400; no configured secret → 500; a redelivered
  event applies once; a second success event can't change a paid order;
  amount or currency mismatch is flagged, not paid; unknown intents and
  event types are acknowledged; a failed attempt records nothing and the
  retry succeeds; a payment landing after its order was cancelled is
  flagged for a refund, not sold. Each protection was removed in turn to confirm its test
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

| Commit | Adds                                                              |
| ------ | ----------------------------------------------------------------- |
| 10     | Order emails and a signed "view your order" link                  |
| 11     | Admin orders: mark shipped, refund (calls Stripe, unlike Fable's) |
| 12     | Disputes and dashboard refunds via webhooks                       |

## Known gaps

- **Not yet run with real Stripe:** the card form, `confirmPayment()` and
  the redirect have only run without a Stripe key. Try a full test-mode
  payment once `STRIPE_KEY`/`STRIPE_SECRET` are set (cards `4242…` success,
  `4000 0000 0000 0002` decline, `4000 0027 6000 3184` 3D Secure).
- **US shipping only:** the form sends `country: US`; international needs
  a country field and UPS international services.
- **Buy section placement on phones:** on pieces with many photos it sits
  below all the thumbnails, so buyers scroll to find it.
- **One default box size** for UPS rates until pieces store size and
  weight.
