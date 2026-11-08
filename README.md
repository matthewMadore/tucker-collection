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
    npm run dev

Visit `http://localhost`. Mailpit (local email inbox) is at `http://localhost:8025`.

The storage link is relative on purpose. `php artisan storage:link` writes an
absolute path, so a link made on the Mac points at a path the container can't
see (and the reverse), and uploaded images return 403.
