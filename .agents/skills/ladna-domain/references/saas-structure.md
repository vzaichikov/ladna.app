# Ladna SaaS Structure

## Core Account Levels

- Platform owner: Ladna product owner and super admin. In code, this is `User.system_role = platform_admin`. Platform admins manage SaaS customer accounts and `SubscriptionPlan` records.
- Studio owner: SaaS customer who pays for Ladna access. In code, this is an `AccountMembership` with role `owner` for a specific `Account`. Studio owners manage only their own studio account.
- Studio staff: Ladna users working inside one studio account. In code, these are `AccountRole` values `admin`, `manager`, `trainer`, and `receptionist`, controlled by `StudioPermission`.
- Studio customer: person attending classes. In code, this is `Customer`, scoped to one `Account`, and separate from Ladna `User`.

## Tenant Model

- `Account` is the studio business tenant.
- `Location` belongs to an account and represents a studio/location.
- `Room` belongs to an account and location.
- `ActivityDirection`, `ClassType`, `ScheduleSeries`, `ScheduledClass`, and `ClassBooking` belong to the account.
- `Trainer` belongs to the account and may optionally link to a Ladna `User` through staff login.
- Studio customer data must not cross accounts.

## Access Rules

- Platform admins can access platform-level management and may bypass tenant gates when needed for support or administration.
- Studio owners can manage their own `Account`, locations, rooms, trainers, schedules, customers, class pass plans, and bookings.
- Staff access is permission-based. Use `StudioPermission` instead of hard-coding role checks when a capability can vary by staff member.
- Studio owners and staff must not create other SaaS studio-owner accounts. New SaaS customer accounts belong to platform flows.

## Money Flows

- SaaS subscription: `SubscriptionPlan` and `AccountSubscription`. The studio owner pays the platform owner for CRM access.
- Studio class pass: `ClassPassPlan` and single-class payment concepts. The studio customer pays the studio owner for training access.
- Do not merge subscription billing and class-pass billing concepts; they belong to different business relationships.

## Scheduling And Sales

- Weekly recurring schedule lives in `ScheduleSeries`.
- Generated concrete classes live in `ScheduledClass`.
- Attendance and booking state live in `ClassBooking`.
- `ClassType` is the studio-scoped catalog item for one concrete sellable/service format. Keep it as one table with `schedule_kind`; do not split group classes, private lessons, and room rental into separate physical tables unless the product explicitly approves a larger migration.
- `ScheduleKindRegistry` is the code-facing behavior registry for schedule formats. It defines the route namespace, UI label keys, capacity/person-count label, recurring/manual behavior, public schedule behavior, and default visibility for each `ScheduleKind`. Add future formats there first.
- `Account.enabled_schedule_kinds` controls which schedule formats are visible for a studio. Existing or unset accounts should behave as if group classes, private lessons, and room rental are all enabled.
- `Account.schedule_kind_colors` stores studio-specific visual colors for schedule formats. These colors are account-level settings, not per-`ClassType` business data; missing values fall back to `ScheduleKindRegistry` defaults.
- Group classes (`group_class`) are recurring formats managed through `ScheduleSeries`; generated occurrences are public schedule candidates.
- Private lessons (`private_lesson`) and room rental (`room_rental`) are manual one-off `ScheduledClass` records with `schedule_series_id = null` and `is_generated = false`. Do not put them into weekly recurring schedule flows by default.
- For group classes, `capacity` means maximum attendance. For private lessons and room rental, `capacity` is displayed as people count inside one purchase/booking, not as remaining saleable seats.
- Private lesson and room rental scheduled slots allow only one active booking from one customer at a time. A second customer must not be able to book the same slot just because the people count is greater than one.
- Studio calendar blocks should combine colors: the main block/background comes from the `ActivityDirection`, while the small terminal border/badge comes from `Account.schedule_kind_colors` for the class type's `schedule_kind`.
- The public schedule shows only enabled group classes. Private lessons and room rental may appear in public pricing, but they need separate public purchase/booking interfaces before being exposed as schedule CTA flows.
- `ClassPassPlan` is the studio-scoped sellable pass or price template. It has its own explicit `schedule_kind` and binds to eligible `ClassType` records of that same schedule kind, optional `TrainerType` records, optional `Room` records, session count, validity after first use, total validity from purchase, time restrictions, price, currency, and the `is_trial` marker.
- One `ClassPassPlan` belongs to exactly one `ScheduleKind`. Group passes may bind to many group class types; private lesson and rental plans must bind to exactly one matching class type.
- The studio UI labels the pass catalog as "Class passes & prices" / "Абонементи і ціни" and separates the index into tabs by `ClassPassPlan.schedule_kind`.
- Trial passes are ordinary `ClassPassPlan` records with `is_trial = true`; they may be issued only once to customers with no previous booking in the same studio account.
- A purchased pass is `CustomerClassPass`, scoped to one `Account` and one `Customer`. It has a globally unique human code such as `XTY2-GFTR`, purchase date, separate opening date, expiry date, snapshot price/name/session fields, status, and active marker. One customer may have many active purchased passes.
- A purchased pass does not open at purchase time. Opening happens on first used attendance, and expiry after opening is calculated from `opened_at + validity_days`. Every purchased pass also has a total validity cap from `purchased_at + total_validity_days`; that cap can expire even unopened passes.
- `CustomerClassPassReservation` is the ledger between a booking and the purchased pass. Future bookings reserve sessions; attended bookings consume sessions; released/cancelled/no-show bookings return the reservation.
- Remaining sessions are derived as total sessions minus used sessions; reserved sessions are tracked separately so overbooking is visible before attendance. Counters are normalized from ledger rows by the `class-passes:normalize` command and nightly scheduler.
- Booking may proceed without a suitable active pass. Studio UI must show an alert on the booking row so staff can charge the customer at visit time.
- When a booking needs a pass, use the suitable active purchased pass with the earliest `purchased_at`, then lowest id. Suitability is checked against the scheduled class account, class type, optional room, optional trainer type, remaining unreserved sessions, expiry after opening, and total validity from purchase.
- Public price output is built from active `ClassPassPlan` records for one account/location and is exposed as both HTML (`/{accountSlug}/{locationSlug}/price`) and JSON API (`/api/v1/public/{accountSlug}/{locationSlug}/price`), grouped by `ClassPassPlan.schedule_kind`.

## Demo Defaults

- Platform user: `platform-owner@example.test`.
- Studio owner: `studio-owner@example.test`.
- Demo account/location: `Charmpole`.
- Demo rooms: `Великий зал` and `Малий зал`.
- Demo data should model the real Charmpole studio, not generic placeholder fitness data. Charmpole demo class-pass plans include group classes, private lessons, room rental, and one trial pass.
- `App\Support\CharmpoleDemoCatalog` is the code source of truth for Charmpole demo directions, rooms, trainer type defaults, class types, pass plans, weekly schedule rows, customers, and demo customer passes. Update it first when changing demo catalog content, then let seeders consume it.
- `php artisan ladna:sync-charmpole-catalog` is a guarded catalog-only sync for existing Charmpole accounts. It dry-runs by default and only applies changes with `--execute`. It must not replace accounts, users, customers, trainers, trainer types, brand settings, SMS settings, or integration settings; it is for directions, class types, class-pass plans, and their pivots only.
