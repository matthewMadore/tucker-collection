<?php

use App\Contracts\PaymentGateway;
use App\Gateways\FakeStripePaymentGateway;
use App\Gateways\StripePaymentGateway;
use App\Models\Order;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

/**
 * Stands in for the network under the real Stripe SDK: records each request
 * and answers with queued JSON responses, so the SDK's own request building
 * (URLs, form params, headers) is what gets tested.
 */
class RecordingStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}> */
    public array $requests = [];

    /** @param list<array{0: int, 1: array<string, mixed>}> $responses */
    public function __construct(private array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];
        [$status, $body] = array_shift($this->responses);

        return [json_encode($body), $status, []];
    }
}

beforeEach(function () {
    $this->useStripeResponses = function (array ...$responses): RecordingStripeHttpClient {
        $client = new RecordingStripeHttpClient($responses);
        ApiRequestor::setHttpClient($client);

        return $client;
    };
    $this->gateway = new StripePaymentGateway(new StripeClient(['api_key' => 'sk_test_123', 'max_network_retries' => 0]));
    $this->alreadySucceeded = [400, ['error' => ['type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state', 'message' => 'This PaymentIntent has already succeeded.']]];
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

test('creating a payment sends the order total, currency, receipt email and an idempotency key', function () {
    $order = Order::factory()->create(['total_cents' => 43_500, 'currency' => 'USD', 'buyer_email' => 'buyer@example.com']);
    $http = ($this->useStripeResponses)([200, ['id' => 'pi_123', 'object' => 'payment_intent', 'client_secret' => 'pi_123_secret_abc']]);

    $intent = $this->gateway->createIntent($order);

    expect($intent->id)->toBe('pi_123')
        ->and($intent->clientSecret)->toBe('pi_123_secret_abc');

    $request = $http->requests[0];
    expect($request['method'])->toBe('post')
        ->and($request['url'])->toBe('https://api.stripe.com/v1/payment_intents')
        ->and($request['params'])->toMatchArray([
            'amount' => 43_500,
            'currency' => 'usd',
            'receipt_email' => 'buyer@example.com',
            'metadata' => ['order_id' => (string) $order->id],
            'automatic_payment_methods' => ['enabled' => true],
        ])
        ->and($request['headers'])->toContain("Idempotency-Key: order-{$order->id}-payment-intent");
});

test('cancelling a payment calls Stripe\'s cancel endpoint', function () {
    $http = ($this->useStripeResponses)([200, ['id' => 'pi_123', 'object' => 'payment_intent', 'status' => 'canceled']]);

    $this->gateway->cancelIntent('pi_123');

    expect($http->requests)->toHaveCount(1)
        ->and($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/payment_intents/pi_123/cancel');
});

test('cancelling a payment that is already cancelled is not an error', function () {
    ($this->useStripeResponses)(
        [400, ['error' => ['type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state', 'message' => 'This PaymentIntent has already been canceled.']]],
        [200, ['id' => 'pi_123', 'object' => 'payment_intent', 'status' => 'canceled']],
    );

    $this->gateway->cancelIntent('pi_123');
})->throwsNoExceptions();

test('cancelling a payment that already succeeded throws, so the hold is kept', function () {
    ($this->useStripeResponses)(
        $this->alreadySucceeded,
        [200, ['id' => 'pi_123', 'object' => 'payment_intent', 'status' => 'succeeded']],
    );

    $this->gateway->cancelIntent('pi_123');
})->throws(InvalidRequestException::class, 'This PaymentIntent has already succeeded.');

test('refunding a payment refunds it in full with one idempotency key per payment', function () {
    $http = ($this->useStripeResponses)([200, ['id' => 're_123', 'object' => 'refund', 'status' => 'succeeded']]);

    $this->gateway->refund('pi_123');

    expect($http->requests[0]['url'])->toBe('https://api.stripe.com/v1/refunds')
        ->and($http->requests[0]['params'])->toBe(['payment_intent' => 'pi_123'])
        ->and($http->requests[0]['headers'])->toContain('Idempotency-Key: refund-pi_123');
});

test('refunding a payment that is already refunded is not an error', function () {
    ($this->useStripeResponses)([400, ['error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded', 'message' => 'Charge ch_123 has already been refunded.']]]);

    $this->gateway->refund('pi_123');
})->throwsNoExceptions();

test('any other refund error throws, so nothing is marked refunded', function () {
    ($this->useStripeResponses)([400, ['error' => ['type' => 'invalid_request_error', 'code' => 'charge_disputed', 'message' => 'Charge ch_123 has been charged back.']]]);

    $this->gateway->refund('pi_123');
})->throws(InvalidRequestException::class, 'charged back');

test('the app uses real Stripe only when a secret key is configured', function (?string $secret, string $gateway) {
    config(['services.stripe.secret' => $secret]);

    expect(app(PaymentGateway::class))->toBeInstanceOf($gateway);
})->with([
    'no key: fake gateway' => [null, FakeStripePaymentGateway::class],
    'key set: real Stripe' => ['sk_test_123', StripePaymentGateway::class],
]);
