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
- `ClassPassPlan` is the studio-scoped sellable pass template. It binds to eligible `ClassType` records, optional `TrainerType` records, optional `Room` records, session count, validity after first use, time restrictions, price, currency, and the `is_trial` marker.
- `ClassType.schedule_kind` determines whether a pass is sold as group classes, private lessons, or room rental. Group passes usually bind to many class types; private lesson passes usually bind to private class types plus trainer types; rental passes bind to rental class types plus rooms.
- Trial passes are ordinary `ClassPassPlan` records with `is_trial = true`; they may be issued only once to customers with no previous booking in the same studio account.
- A purchased pass is `CustomerClassPass`, scoped to one `Account` and one `Customer`. It has a globally unique human code such as `XTY2-GFTR`, purchase date, separate opening date, expiry date, snapshot price/name/session fields, status, and active marker. One customer may have many active purchased passes.
- A purchased pass does not open at purchase time. Opening happens on first used attendance, and expiry is calculated from `opened_at + validity_days`. Unopened passes do not expire automatically.
- `CustomerClassPassReservation` is the ledger between a booking and the purchased pass. Future bookings reserve sessions; attended bookings consume sessions; released/cancelled/no-show bookings return the reservation.
- Remaining sessions are derived as total sessions minus used sessions; reserved sessions are tracked separately so overbooking is visible before attendance. Counters are normalized from ledger rows by the `class-passes:normalize` command and nightly scheduler.
- Booking may proceed without a suitable active pass. Studio UI must show an alert on the booking row so staff can charge the customer at visit time.
- When a booking needs a pass, use the suitable active purchased pass with the earliest `purchased_at`, then lowest id. Suitability is checked against the scheduled class account, class type, optional room, optional trainer type, remaining unreserved sessions, and expiry.
- Public price output is built from active `ClassPassPlan` records for one account/location and is exposed as both HTML (`/{accountSlug}/{locationSlug}/price`) and JSON API (`/api/v1/public/{accountSlug}/{locationSlug}/price`), grouped by `ClassType.schedule_kind`.

## Demo Defaults

- Platform user: `platform-owner@example.test`.
- Studio owner: `studio-owner@example.test`.
- Demo account/location: `Charmpole`.
- Demo rooms: `Великий зал` and `Малий зал`.
- Demo data should model the real Charmpole studio, not generic placeholder fitness data. Charmpole demo class-pass plans include group classes, private lessons, room rental, and one trial pass.
