# Feature: Public Gallery, Publishing and Accounts

The site has two sides backed by the same app and database:

- **Public** (no login): a gallery of published pieces at `/` and a page
  per piece at `/art/{artPiece}`.
- **Admin** (logged in): the dashboard, uploads and per-piece status
  controls, under `/dashboard` and `/art-pieces/...`.

A piece's `status` is the only link between them: drafts are admin-only;
every other status is public.

**Status (2026-11-14):** implemented locally. Registration is closed;
accounts are created from the command line.

## Behavior

- **Statuses:** Draft, Published, On hold, Sold. New pieces start as
  Draft. The admin detail page has one button per status; the current
  one is highlighted. Changing it flashes a toast ("Status changed to
  Published.") and, for non-drafts, shows a "View public page" link.
  Only moves the state machine allows are enabled (below).
- **Public gallery:** published, held and sold pieces, newest first,
  24 per page. Each card shows the first image (by `position`), name and
  price. Held and sold pieces carry an "On hold" / "Sold" badge, so the
  gallery shows the full body of work.
- **Public piece page:** all images with a thumbnail strip, price and
  description. A draft returns 404, the same as a missing piece, so its
  existence isn't revealed.
- **Accounts:** sign-up is disabled (`/register` is 404). Anyone with an
  account can manage every piece; only the people who run the site have
  accounts.
- **iPhone:** Safari → Share → **Add to Home Screen** installs the site
  as a full-screen app (icon "TC", opens at `/dashboard`). The upload
  dialog says "Tap to choose photos" on touch screens.

## Routes

| Method | Path                            | Name                       | Access            | Handler                                                                                   |
| ------ | ------------------------------- | -------------------------- | ----------------- | ----------------------------------------------------------------------------------------- |
| GET    | `/`                             | `home`                     | public            | [GalleryController::index](../../app/Http/Controllers/GalleryController.php#L15)          |
| GET    | `/art/{artPiece}`               | `gallery.show`             | public, non-draft | [GalleryController::show](../../app/Http/Controllers/GalleryController.php#L35)           |
| GET    | `/art-pieces/{artPiece}`        | `art-pieces.show`          | auth + verified   | [ArtPieceController::show](../../app/Http/Controllers/ArtPieceController.php#L40)         |
| PATCH  | `/art-pieces/{artPiece}/status` | `art-pieces.status.update` | auth + verified   | [ArtPieceController::updateStatus](../../app/Http/Controllers/ArtPieceController.php#L60) |

Defined in [routes/web.php](../../routes/web.php#L7).

## Call chain: publishing a piece

| #   | Location                                                                                   | Responsibility                                                                                         |
| --- | ------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------ |
| 1   | [art-pieces/Show.vue:42](../../resources/js/pages/art-pieces/Show.vue#L42)                 | `changeStatus()` sends `PATCH /art-pieces/{id}/status` with `{ status }` via `useForm`.                |
| 2   | [routes/web.php:14](../../routes/web.php#L14)                                              | Inside the `auth` + `verified` group: guests are redirected to login.                                  |
| 3   | [UpdateArtPieceStatusRequest.php](../../app/Http/Requests/UpdateArtPieceStatusRequest.php) | `Rule::enum(ArtPieceStatus::class)`: anything else fails with "The selected status is invalid."        |
| 4   | [ArtPieceController.php:60](../../app/Http/Controllers/ArtPieceController.php#L60)         | Saves the status, flashes the toast, redirects back. Inertia reloads the page props.                   |
| 5   | Postgres                                                                                   | The `status_valid` CHECK constraint rejects unknown values even if application validation is bypassed. |

## Call chain: a visitor opens the gallery

| #   | Location                                                                         | Responsibility                                                                                             |
| --- | -------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| 1   | [routes/web.php:7](../../routes/web.php#L7)                                      | Public route, no middleware beyond `web`.                                                                  |
| 2   | [GalleryController.php:15](../../app/Http/Controllers/GalleryController.php#L15) | `ArtPiece::visibleToPublic()`, images eager-loaded in `position` order, paginated.                         |
| 3   | [ArtPiece.php:47](../../app/Models/ArtPiece.php#L47)                             | The `visibleToPublic` scope: `WHERE status IN ('published', 'held', 'sold')`.                              |
| 4   | [gallery/Index.vue](../../resources/js/pages/gallery/Index.vue)                  | Renders inside [PublicLayout.vue](../../resources/js/layouts/PublicLayout.vue) (logo, Log in / Dashboard). |

## Key decisions

- **One source of truth for statuses:** [ArtPieceStatus.php](../../app/Enums/ArtPieceStatus.php)
  holds the values, labels and `isPublic()`. The model casts `status` to
  it, validation uses `Rule::enum`, and the admin page gets its buttons
  from `ArtPieceStatus::options()`. The values match the database CHECK
  constraint.
- **Status state machine:** `allowedTransitions()` on the enum lists
  every legal move; anything else is illegal. Two shared traits implement
  it for every status in the app (pieces, orders, payments):
  [HasStatusTransitions](../../app/Concerns/HasStatusTransitions.php) on
  the enum (`canTransitionTo()`), and
  [TransitionsStatus](../../app/Concerns/TransitionsStatus.php) on the
  model (`transitionTo($status, [extra columns])`, which throws on an
  illegal move and saves nothing). The admin request rejects an illegal
  move with "Can't change status from Sold to Draft."

    | From      | Allowed to                        |
    | --------- | --------------------------------- |
    | Draft     | Published, Sold (offline sale)    |
    | Published | Draft, On hold, Sold              |
    | On hold   | Published, Sold                   |
    | Sold      | Published (relist after a refund) |

- **Shared image viewer:** [ArtPieceImageGallery.vue](../../resources/js/components/ArtPieceImageGallery.vue)
  is used by both the admin and public piece pages.
- **Closed registration over an admin role:** with two accounts, created
  by the owner, an `is_admin` flag adds nothing yet. Fortify's
  registration feature is removed in [config/fortify.php](../../config/fortify.php#L148),
  and [CreateUser.php](../../app/Console/Commands/CreateUser.php)
  (`php artisan app:create-user`) creates verified accounts. It marks the
  email verified because the person running it is the account owner, and
  outgoing mail isn't configured yet.
- **Installable web app, not an App Store app:** a
  [web app manifest](../../public/manifest.webmanifest) and the iPhone
  meta tags in [app.blade.php](../../resources/views/app.blade.php#L38)
  give a home-screen icon and full-screen launch with no extra build or
  review process. Icons: `public/icon.svg` (source),
  `apple-touch-icon.png` (180), `icon-192.png`, `icon-512.png`.

## Tests

- [GalleryTest.php](../../tests/Feature/GalleryTest.php): public listing
  excludes drafts and orders newest first; held/sold labels; cover image
  is the first by position; public piece page; drafts 404.
- [ArtPieceStatusUpdateTest.php](../../tests/Feature/ArtPieceStatusUpdateTest.php):
  guests redirected; publishing persists and flashes a toast; unknown
  status rejected.
- [RegistrationClosedTest.php](../../tests/Feature/RegistrationClosedTest.php):
  the register page and endpoint are 404 and create no user.
- [CreateUserCommandTest.php](../../tests/Feature/CreateUserCommandTest.php):
  creates a verified user who can log in; rejects a duplicate email.
- [ArtPieceShowTest.php](../../tests/Feature/ArtPieceShowTest.php): the
  admin page receives the status options.
- Manually verified at iPhone size (Playwright, Chrome mobile
  emulation): every status button, upload and submit, gallery and
  navigation links. Not yet verified on a real iPhone: Add to Home
  Screen and HEIC photo uploads.

## Open items

1. **Real-device check.** Add to Home Screen and a HEIC upload from an
   iPhone, once deployed.
2. **Branding.** `APP_NAME` is still "Laravel" and the header logo is
   Laravel's (`AppLogoIcon.vue`); the "TC" icon is a placeholder.
3. **Location metadata.** Published images are now public, so stripping
   GPS EXIF data ([image-upload.md](image-upload.md#open-items), item 6)
   should land before launch.
4. **Editing and deleting pieces.** Not built; only status can change
   after creation.
5. **Admin page layout on phones.** Pieces with many images push the
   status buttons below the thumbnail strip.
