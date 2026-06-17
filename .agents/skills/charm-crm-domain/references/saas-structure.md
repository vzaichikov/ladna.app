# Charm CRM SaaS Structure

## Core Account Levels

- Platform owner: CRM product owner and super admin. In code, this is `User.system_role = platform_admin`. Platform admins manage SaaS customer accounts and `SubscriptionPlan` records.
- Studio owner: SaaS customer who pays for CRM access. In code, this is an `AccountMembership` with role `owner` for a specific `Account`. Studio owners manage only their own studio account.
- Studio staff: CRM users working inside one studio account. In code, these are `AccountRole` values `admin`, `manager`, `trainer`, and `receptionist`, controlled by `StudioPermission`.
- Studio customer: person attending classes. In code, this is `Customer`, scoped to one `Account`, and separate from CRM `User`.

## Tenant Model

- `Account` is the studio business tenant.
- `Location` belongs to an account and represents a studio/location.
- `Room` belongs to an account and location.
- `ActivityDirection`, `ClassType`, `ScheduleSeries`, `ScheduledClass`, and `ClassBooking` belong to the account.
- `Trainer` belongs to the account and may optionally link to a CRM `User` through staff login.
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
- `ClassPassPlan` describes what a studio customer can buy for classes, including eligible directions, session count, validity, and time restrictions.

## Demo Defaults

- Platform user: `platform-owner@example.test`.
- Studio owner: `studio-owner@example.test`.
- Demo account/location: `Charmpole`.
- Demo rooms: `Великий зал` and `Малий зал`.
- Demo data should model the real Charmpole studio, not generic placeholder fitness data.
