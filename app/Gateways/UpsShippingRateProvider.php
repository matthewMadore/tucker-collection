<?php

namespace App\Gateways;

use App\Contracts\ShippingRateProvider;
use App\Models\ArtPiece;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Live UPS Ground rates (Rating API v2409), signature required, with the
 * piece's price as declared value. Used whenever UPS_CLIENT_ID is set.
 * If UPS fails, checkout still works at the flat rate, and a warning is
 * logged.
 */
class UpsShippingRateProvider implements ShippingRateProvider
{
    private const string TOKEN_CACHE_KEY = 'ups.oauth_token';

    /**
     * @param  array{client_id: string, client_secret: string, account_number: string, base_url: string, ship_from: array<string, string>, default_package: array<string, int|string>}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly FlatRateShippingProvider $fallback,
    ) {}

    public function quote(ArtPiece $piece, array $shippingAddress): ShippingQuote
    {
        try {
            return $this->requestRate($piece, $shippingAddress);
        } catch (Throwable $exception) {
            Log::warning('UPS rating failed; charging the flat shipping rate.', [
                'art_piece_id' => $piece->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->fallback->quote($piece, $shippingAddress);
        }
    }

    /**
     * @param  array<string, string>  $shippingAddress
     *
     * @throws RequestException
     */
    private function requestRate(ArtPiece $piece, array $shippingAddress): ShippingQuote
    {
        $account = $this->config['account_number'];
        $from = $this->config['ship_from'];
        $box = $this->config['default_package'];

        // UPS types every number as a string.
        $response = Http::withToken($this->accessToken())
            ->withHeaders(['transId' => Str::random(32), 'transactionSrc' => config('app.name')])
            ->post("{$this->config['base_url']}/api/rating/v2409/Rate", ['RateRequest' => [
                'Request' => ['SubVersion' => '2409', 'TransactionReference' => ['CustomerContext' => "art-piece-{$piece->id}"]],
                'Shipment' => [
                    'Shipper' => ['Name' => $from['name'], 'ShipperNumber' => $account, 'Address' => $this->upsAddress($from)],
                    'ShipTo' => ['Name' => $shippingAddress['name'], 'Address' => $this->upsAddress($shippingAddress)],
                    'PaymentDetails' => ['ShipmentCharge' => [['Type' => '01', 'BillShipper' => ['AccountNumber' => $account]]]],
                    'Service' => ['Code' => '03', 'Description' => 'Ground'],
                    'NumOfPieces' => '1',
                    'ShipmentRatingOptions' => ['NegotiatedRatesIndicator' => 'Y'],
                    'Package' => [[
                        'PackagingType' => ['Code' => '02', 'Description' => 'Package'],
                        'Dimensions' => [
                            'UnitOfMeasurement' => ['Code' => 'IN', 'Description' => 'Inches'],
                            'Length' => (string) $box['length'],
                            'Width' => (string) $box['width'],
                            'Height' => (string) $box['height'],
                        ],
                        'PackageWeight' => ['UnitOfMeasurement' => ['Code' => 'LBS', 'Description' => 'Pounds'], 'Weight' => (string) $box['weight']],
                        'PackageServiceOptions' => [
                            // "2" at package level = delivery confirmation, signature required.
                            'DeliveryConfirmation' => ['DCISType' => '2'],
                            'DeclaredValue' => ['CurrencyCode' => $piece->currency, 'MonetaryValue' => number_format($piece->price_cents / 100, 2, '.', '')],
                        ],
                    ]],
                ],
            ]])
            ->throw();

        // RatedShipment is always an array from v2409. Prefer the account's
        // negotiated rate when UPS returns one.
        $rated = $response->json('RateResponse.RatedShipment.0');
        $total = data_get($rated, 'NegotiatedRateCharges.TotalCharge.MonetaryValue')
            ?? data_get($rated, 'TotalCharges.MonetaryValue')
            ?? throw new UnexpectedValueException('UPS response had no total charge.');

        return new ShippingQuote(
            carrier: 'ups',
            serviceLevel: 'UPS Ground, signature required',
            rateCents: (int) round((float) $total * 100),
            estimatedDays: (int) (data_get($rated, 'GuaranteedDelivery.BusinessDaysInTransit') ?? 5),
        );
    }

    /**
     * OAuth client-credentials token, cached until a minute before it expires.
     *
     * @throws RequestException
     */
    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached)) {
            return $cached;
        }

        $token = Http::asForm()
            ->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
            ->withHeaders(['x-merchant-id' => $this->config['account_number']])
            ->post("{$this->config['base_url']}/security/v1/oauth/token", ['grant_type' => 'client_credentials'])
            ->throw();

        $accessToken = (string) $token->json('access_token');
        Cache::put(self::TOKEN_CACHE_KEY, $accessToken, max(60, (int) $token->json('expires_in') - 60));

        return $accessToken;
    }

    /**
     * @param  array<string, string>  $address
     * @return array<string, mixed>
     */
    private function upsAddress(array $address): array
    {
        return [
            'AddressLine' => array_values(array_filter([$address['line1'] ?? null, $address['line2'] ?? null])),
            'City' => $address['city'] ?? '',
            'StateProvinceCode' => $address['state'] ?? '',
            'PostalCode' => $address['postal_code'] ?? '',
            'CountryCode' => $address['country'] ?? 'US',
        ];
    }
}
