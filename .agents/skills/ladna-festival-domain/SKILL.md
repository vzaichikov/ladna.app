---
name: ladna-festival-domain
description: Use for any Ladna Festival framework work, including Festival Series and editions, portal identity or authentication, participants, categories and typed rules, performance entries, requirements and private files, entry charges and payments, stages and schedules, judging and results, admission tickets and QR scans, Festival notifications, public or staff interfaces, permissions, migrations, and architecture decisions.
---

# Ladna Festival Domain

## Start With The Canonical Model

If `.codex/docs/festivals/README.md` exists, read it completely before changing Festival behavior. Treat it as local investigation and verification context that may be absent from a clean clone.

Treat the rules in this skill plus current routes, models, migrations, policies, and tests as the enforceable source of truth. If implementation and local documentation disagree, verify the code and tests before resolving the discrepancy.

## Preserve The Domain Boundary

- Scope every Festival identity and business record to one `Account`.
- Keep `FestivalPortalUser` and `FestivalParticipant` completely separate from `Customer`. Do not add an optional link, lookup, synchronization, migration, merge, or fallback.
- Keep Festival records completely separate from `Event`. Do not add Event foreign keys, shared aggregates, lifecycle coupling, or calls into Event ticket models.
- Use existing `User` records only for organizer and staff access. Use the dedicated Festival guard for registrants and guest judges.
- Require an active edition assignment for a staff or guest judge unless a Festival manager is acting through an explicitly authorized management flow.
- Keep `accounts.enable_festivals` disabled by default and enforce it at every Festival entrypoint.
- Treat `FestivalTariffPackage` as a one-time plan add-on and `FestivalEditionPurchase` as the only self-service entitlement for creating a new edition. Never fold Festival pricing into recurring location tiers.
- Only the studio owner may initiate a Festival purchase; Festival managers may redeem an already available entitlement. Zero-price packages grant access without a gateway payment or fiscal receipt.
- Snapshot tariff/package names, amount, currency, participant limit, and guest-ticket limit on the purchase. Never recalculate an existing entitlement after tariff or package changes.
- Count distinct participants only across submitted, under-review, and accepted performances. Count paid guest orders and unexpired pending holds, including free tickets. Lock the edition purchase row before enforcing either quota.
- A reversed redeemed purchase keeps all Festival data but makes that edition read-only until platform resolution.
- Reuse generic Ladna infrastructure only through Festival-owned actions and records. Payment gateways, storage, mail, queues, and secure QR design are reusable; Customer/Event domain models are not.
- Keep domain actions channel-neutral. A future Telegram bot or Mini App must adapt the same actions and attach Telegram identity to an existing account-scoped Festival portal user.

## Model Festival Work Correctly

- Treat `FestivalSeries` as reusable organizer and brand defaults, and `FestivalEdition` as one independently frozen dated competition.
- Treat `FestivalEntry` as one performance containing one or more roster participants. Never model the participant as the application aggregate.
- Keep edition, registration, entry review, qualification, requirement review, payment, scheduling, score-sheet, result-publication, and admission-ticket states independent.
- Derive readiness from all applicable gates. Do not persist a loosely synchronized ready flag or call query-producing readiness helpers inside collection loops.
- Snapshot category labels, typed rules, age on the edition reference date, coach/studio data, prices, currency, and other historical facts when an entry is submitted or charged.
- Version categories, rules, prices, requirements, uploads, and rubrics after use. Do not destructively rewrite facts referenced by submitted entries, payments, schedules, or scores.
- Store entry submissions and payment proofs privately and serve them only through account- and role-authorized controllers.
- Keep participation charges separate from spectator admission orders and tickets.
- Lock submitted score sheets. Require an authorized, reasoned, audited unlock before further edits.
- Keep public totals, ranks, and medals separate from private per-judge scores and comments.
- Preserve immutable audit, payment-attempt, notification, upload-review, schedule-change, score-lock, and ticket-scan history.

## Implement Through Festival-Owned Surfaces

1. Identify the affected aggregate and independent state machine before editing code.
2. Map account binding, guard, permission, edition assignment, and capability checks for every entrypoint.
3. Keep implementation under Festival-specific models, actions, requests, controllers, policies, routes, views, jobs, mail, and tables.
4. Use numeric model binding for staff administration and generated stable public slugs according to the lifecycle documented in the canonical model.
5. Authorize each staff workspace route on the server. Hiding a menu item is never authorization.
6. Query only data that the current permission and screen need. Do not expose applicant contact data to check-in or judge roles, or Festival revenue to non-finance roles.
7. Use transactions and row locks for inventory, scheduling collisions, payment state, score submission, and other concurrent mutations.
8. Queue Festival notifications only after commit with immutable payloads, stable deduplication, retry safety, and delivery-time state revalidation.
9. When `.codex/docs/festivals/README.md` exists, update its affected sections and verification record without making application behavior or tests depend on that ignored local file.

## Activate Adjacent Skills Deliberately

- Use `laravel-best-practices` for Festival PHP, Laravel, database, routing, controller, policy, or test changes.
- Use `ladna-domain` only when the change also touches shared `Account`, `User`, SaaS-role, platform, or non-Festival tenancy behavior.
- Use `tailwindcss-development` for Festival Blade, sidebar, responsive layout, card, or theme work.
- Use `ladna-testing` for rendered desktop/mobile QA, screenshots, and browser-log inspection.
- Use `ladna-help-update` when owner-facing Festival behavior, navigation, or copy changes.
- Use `ladna-api-docs-update` only when a public Ladna API contract changes. Do not add a public Festival JSON API unless the product explicitly enters the Telegram/Mini App phase.
- Use `ladna-versioning` before every commit or push, and `ladna-production-deploy` only when deployment is explicitly requested.

## Verify The Boundary And Behavior

- Prove cross-account access fails for staff, portal, files, judges, callbacks, orders, tickets, and scanners.
- Prove Festival workflows neither create nor require `Customer` or `Event` records. Keep the Festival architecture-boundary test current.
- Cover every changed state transition, immutable snapshot, authorization branch, idempotency rule, concurrent mutation, and public/private visibility boundary.
- Use the configured dedicated test database and the repository's transactional PHPUnit convention. Never rebuild the development database.
- Run the singular changed test, related Festival suites, unchanged Event regression suites, and the complete compact suite in proportion to the change.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes and `npm run build` after frontend changes.
- Complete authenticated desktop and mobile rendered QA for interface changes, inspect screenshots visually, and check recent browser logs.
