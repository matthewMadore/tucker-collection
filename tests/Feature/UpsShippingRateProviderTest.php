<?php

use App\Contracts\ShippingRateProvider;
use App\Gateways\FlatRateShippingProvider;
use App\Gateways\UpsShippingRateProvider;
use App\Models\ArtPiece;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->config = [
        'client_id' => 'ups-client',
        'client_secret' => 'ups-secret',
        'account_number' => 'A1B2C3',
        'base_url' => 'https://wwwcie.ups.com',
        'ship_from' => ['name' => 'Tucker Collection', 'line1' => '1 Studio Ln', 'city' => 'Atlanta', 'state' => 'GA', 'postal_code' => '30005', 'country' => 'US'],
        'default_package' => ['length' => 24, 'width' => 20, 'height' => 4, 'weight' => 8],
    ];
    $this->provider = new UpsShippingRateProvider($this->config, new FlatRateShippingProvider);
    $this->piece = ArtPiece::factory()->make(['id' => 7, 'price_cents' => 42_000, 'currency' => 'USD']);
    $this->address = ['name' => 'Ada Lovelace', 'line1' => '1 Analytical Engine Way', 'city' => 'Portland', 'state' => 'OR', 'postal_code' => '97201', 'country' => 'US'];

    $this->token = Http::response(['access_token' => 'token-abc', 'expires_in' => '14399', 'token_type' => 'Bearer']);
    /** @param array<string, mixed> $rated */
    $this->rate = fn (array $rated) => Http::response(['RateResponse' => [
        'Response' => ['ResponseStatus' => ['Code' => '1', 'Description' => 'Success']],
        'RatedShipment' => [$rated],
    ]]);
});

test('a quote asks UPS for a signature-required Ground rate with the piece\'s value declared', function () {
    Http::fake([
        '*/security/v1/oauth/token' => $this->token,
        '*/api/rating/v2409/Rate' => ($this->rate)(['TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '47.23'], 'GuaranteedDelivery' => ['BusinessDaysInTransit' => '4']]),
    ]);

    $quote = $this->provider->quote($this->piece, $this->address);

    expect($quote)
        ->carrier->toBe('ups')
        ->rateCents->toBe(4_723)
        ->estimatedDays->toBe(4);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/security/v1/oauth/token')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('ups-client:ups-secret'))
        && $request['grant_type'] === 'client_credentials');

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/api/rating/v2409/Rate')) {
            return false;
        }
        $shipment = $request['RateRequest']['Shipment'];
        $package = $shipment['Package'][0];

        return $request->hasHeader('Authorization', 'Bearer token-abc')
            && $shipment['Shipper']['ShipperNumber'] === 'A1B2C3'
            && $shipment['Shipper']['Address']['PostalCode'] === '30005'
            && $shipment['ShipTo']['Address'] === ['AddressLine' => ['1 Analytical Engine Way'], 'City' => 'Portland', 'StateProvinceCode' => 'OR', 'PostalCode' => '97201', 'CountryCode' => 'US']
            && $shipment['PaymentDetails']['ShipmentCharge'][0]['BillShipper']['AccountNumber'] === 'A1B2C3'
            && $shipment['Service']['Code'] === '03'
            && $package['PackageWeight']['Weight'] === '8'
            && $package['Dimensions']['Length'] === '24'
            && $package['PackageServiceOptions']['DeliveryConfirmation']['DCISType'] === '2'
            && $package['PackageServiceOptions']['DeclaredValue'] === ['CurrencyCode' => 'USD', 'MonetaryValue' => '420.00'];
    });
});

test('the account\'s negotiated rate is charged when UPS returns one', function () {
    Http::fake([
        '*/security/v1/oauth/token' => $this->token,
        '*/api/rating/v2409/Rate' => ($this->rate)([
            'TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '47.23'],
            'NegotiatedRateCharges' => ['TotalCharge' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '39.10']],
        ]),
    ]);

    expect($this->provider->quote($this->piece, $this->address)->rateCents)->toBe(3_910);
});

test('the UPS login token is reused across quotes until it expires', function () {
    Http::fake([
        '*/security/v1/oauth/token' => $this->token,
        '*/api/rating/v2409/Rate' => ($this->rate)(['TotalCharges' => ['CurrencyCode' => 'USD', 'MonetaryValue' => '47.23']]),
    ]);

    $this->provider->quote($this->piece, $this->address);
    $this->provider->quote($this->piece, $this->address);

    Http::assertSentCount(3);
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth/token')))->toHaveCount(1);
});

test('if UPS fails, the flat rate is charged and a warning is logged', function (Closure $upsResponse) {
    Log::spy();
    Http::fake([
        '*/security/v1/oauth/token' => $this->token,
        '*/api/rating/v2409/Rate' => $upsResponse(),
    ]);

    $quote = $this->provider->quote($this->piece, $this->address);

    expect($quote->rateCents)->toBe((new FlatRateShippingProvider)->quote($this->piece, $this->address)->rateCents);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'UPS rating failed'))->once();
})->with([
    'UPS error response' => [fn () => Http::response(['response' => ['errors' => [['code' => '111285', 'message' => 'The postal code is invalid.']]]], 400)],
    'UPS server error' => [fn () => Http::response('', 500)],
    'response without a total' => [fn () => Http::response(['RateResponse' => ['RatedShipment' => [[]]]])],
]);

test('the app uses live UPS rates only when UPS credentials are configured', function (?string $clientId, string $provider) {
    config(['services.ups.client_id' => $clientId]);

    expect(app(ShippingRateProvider::class))->toBeInstanceOf($provider);
})->with([
    'no credentials: flat rate' => [null, FlatRateShippingProvider::class],
    'credentials set: live UPS' => ['ups-client', UpsShippingRateProvider::class],
]);
