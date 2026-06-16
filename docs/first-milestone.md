# Charm CRM First SaaS Milestone

## Implemented

- SaaS account foundation with accounts, locations, rooms, internal user memberships, roles, customers, activity directions, class types, instructors, weekly schedule series, and generated scheduled classes.
- Platform admin system role on internal users. Platform admins can access `/platform`, view all owner accounts, create/edit/suspend accounts, and assign stub tariff/subscription status.
- Minimal internal Blade/session authentication for users.
- Separate customer model, auth provider/guard-ready config, account links, generic customer login stub, and studio-scoped customer login stub.
- Internal dashboard with account, location, room, activity direction, class type, instructor, and weekly schedule-series CRUD foundations.
- Weekly single-day recurring series generate materialized scheduled classes for the next 8 weeks through `schedule:generate`.
- Public schedule pages and iframe-friendly embed pages with room display and room filter tabs.
- Versioned public JSON schedule API.
- English and Ukrainian language files, account default language for public/customer pages, and session language switcher for the interface.

## Local Setup

```bash
php artisan migrate --no-interaction
php artisan db:seed --no-interaction
npm run build
```

Local URL:

```txt
local-app-url/
```

Demo internal users:

```txt
platform-owner@example.test / password
studio-owner@example.test / password
oxana@example.com / password
```

## Demo URLs

```txt
local-app-url/
local-app-url/dashboard
local-app-url/platform
local-app-url/studio-nastya/location-1/schedule
local-app-url/studio-nastya/location-2/schedule
local-app-url/studio-oxana/main-studio/schedule
local-app-url/studio-nastya/location-1/schedule/embed
local-app-url/studio-nastya/client/login
```

## API Endpoints

```txt
GET /api/v1/public/studio-nastya/location-1/schedule
GET /api/v1/public/studio-nastya/location-1/classes
```

The API returns upcoming public group classes only for active accounts, active locations, active rooms, active class types, and active weekly series. `available_spots` is currently `null`. The response keeps the first milestone fields and now also includes `room`, `class_type`, `activity_direction`, `schedule_kind`, and `booking_cutoff_minutes`.

## Schedule Command

```bash
php artisan schedule:generate
php artisan schedule:generate --series=1
```

The command refreshes non-manually-modified generated future occurrences from active weekly series.

## Intentional Stubs

- Customer login buttons are placeholders for future phone and Google auth.
- Public booking links route to the customer login placeholder.
- Tariff/subscription tables and platform UI are SaaS billing stubs only; no payment provider is integrated.
- Schedule capacity, booking cutoff defaults, and per-series overrides are stored, but bookings, availability calculations, waitlists, cancellations, packages, payments, attendance, payroll, and reminders are not implemented.
- Schedule occurrence editing is read-only in this stage. Weekly series create/update regenerates future generated occurrences.

## Next Steps

- Add real customer authentication flow scoped to each studio.
- Add generated occurrence override/cancel UI.
- Add booking models and availability calculation.
- Add account invitation flow and finer role permissions.
- Add real SaaS billing integration after tariff/subscription stubs are validated.
