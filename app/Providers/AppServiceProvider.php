<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Gateways\FakeStripePaymentGateway;
use App\Gateways\StripePaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Real Stripe when a secret key is configured, the fake otherwise.
        // phpunit.xml clears the key, so tests never call Stripe.
        $this->app->bind(PaymentGateway::class, fn (): PaymentGateway => filled(config('services.stripe.secret'))
            ? new StripePaymentGateway(new StripeClient((string) config('services.stripe.secret')))
            : new FakeStripePaymentGateway);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Card-testing bots hammer checkout with stolen cards; cap attempts per IP.
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
