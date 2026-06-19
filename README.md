# Ladna

Ladna is a Laravel-based SaaS CRM for studios that sell scheduled classes, class passes, and recurring training programs. The current product is tailored around dance and fitness studios, with Ukrainian-first localization and demo data for a studio account.

## Product Scope

- Platform administration for studio accounts, subscription-plan stubs, integrations, and global appearance settings.
- Studio account management with branding, language, currency, timezone, locations, rooms, activity directions, class types, trainer types, trainers, customers, and class pass plans.
- Role-aware internal dashboard for platform admins, account owners, and studio staff.
- Weekly schedule series that generate materialized scheduled classes through an Artisan command.
- Booking management foundations for scheduled classes.
- Public studio schedule pages, iframe-friendly embeds, and versioned public JSON API endpoints.
- English and Ukrainian interface translations.

## Stack

- PHP 8.4 locally, Laravel 13, PHPUnit 12.
- Tailwind CSS 4, Vite 8, Lucide icons.
- SQLite by default for local development; Laravel-supported databases can be configured through environment settings.

## Local Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install --ignore-scripts
npm run build
```

For active frontend development, run:

```bash
npm run dev
```

Open the app using the local URL configured for your machine.

Seeded demo users are defined in `DatabaseSeeder`.

## Schedule Generation

```bash
php artisan schedule:generate
php artisan schedule:generate --series=1
```

The command refreshes future generated occurrences from active weekly schedule series while preserving manually modified classes.

## Public API

```txt
GET /api/v1/public/{accountSlug}/{locationSlug}/schedule
GET /api/v1/public/{accountSlug}/{locationSlug}/classes
```

The public API returns upcoming public group classes for active accounts, locations, rooms, class types, and weekly series.

## Quality Checks

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

## Current Product Notes

Ladna is not yet a full production billing or payment system. Subscription plans, integration settings, customer auth, and booking flows exist as product foundations and are expected to keep evolving.
