# Tucker Collection

A storefront for a finite, private collection of Nickelodeon animation
art — original cels, drawings, and production pieces, each one-of-a-kind.

## Stack

Laravel 12, Vue 3 + Inertia, PostgreSQL, Laravel Sail (Docker)

## Setup

Each step below is a real prerequisite for the next, not boilerplate ritual:

    composer install              # installs vendor/, including the sail script itself
    npm install                   # frontend dependencies (Vue, Inertia, Tailwind)
    cp .env.example .env          # .env is gitignored, so this must be created locally
    php artisan key:generate      # Laravel requires a real APP_KEY to run at all
    ./vendor/bin/sail up -d       # starts the app, Postgres, and Mailpit containers
    ./vendor/bin/sail artisan migrate
    ln -s ../storage/app/public public/storage   # serves uploaded art piece images at /storage
    ./vendor/bin/sail artisan app:create-user    # sign-up is disabled, so logins are created here
    npm run dev
    ./vendor/bin/sail artisan schedule:work      # runs scheduled jobs (releases expired checkout holds)

Visit `http://localhost` for the public gallery and `http://localhost/login` to
manage pieces. Mailpit (local email inbox) is at `http://localhost:8025`.

Payments use a fake Stripe gateway until `STRIPE_SECRET` is set in `.env`, and
shipping uses a flat UPS rate until `UPS_CLIENT_ID` is set.
For Stripe test mode and UPS setup, see
[docs/project/checkout.md](docs/project/checkout.md#running-locally-with-stripe-test-mode).

On an iPhone, open the site in Safari, tap Share, then **Add to Home Screen**.
It opens full screen like an app (see `public/manifest.webmanifest`).

The storage link is relative on purpose. `php artisan storage:link` writes an
absolute path, so a link made on the Mac points at a path the container can't
see (and the reverse), and uploaded images return 403.
