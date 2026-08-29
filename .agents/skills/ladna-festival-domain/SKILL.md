---
name: ladna-festival-domain
description: Use for any Ladna Festival framework work, including Festival Series and editions, portal identity or authentication, participants, categories and typed rules, performance entries, requirements and private files, entry charges and payments, stages and schedules, judging and results, admission tickets and QR scans, Festival notifications, public or staff interfaces, permissions, migrations, and architecture decisions.
---

# Ladna Festival Domain

## Start With The Canonical Model

If `.codex/docs/festivals/README.md` exists, read it completely before changing Festival behavior. Treat it as local investigation and verification context that may be absent from a clean clone, not as authority to add speculative architecture.

Treat the current explicit requirement, this skill, and current routes, models, migrations, policies, and tests as the enforceable source of truth. Historical migrations may still show removed copied snapshots, revisions, version columns, frozen records, or lock generations. They are migration history, not patterns: do not extend, reproduce, or rely on them in Festival designs. Prefer the smallest existing flow that satisfies the current requirement.

Follow the repository's established simplification pattern: direct foreign keys and domain models, one current row for editable input, ordinary status fields, and in-place configuration with `is_active` and `sort_order`. `FestivalCategory` belongs directly to `FestivalDirection` and `FestivalWorkflow`, category requirements live on the category, submissions keep one current row per requirement, and score sheets use the current draft/submitted row. Do not rebuild the removed configuration-version, lock-version, classification-axis, option, or pivot abstractions.

## Preserve The Domain Boundary

- Scope every Festival identity and business record to one `Account`.
- Keep `FestivalPortalUser` and `FestivalParticipant` completely separate from `Customer`. Do not add an optional link, lookup, synchronization, migration, merge, or fallback.
- Keep Festival records completely separate from `Event`. Do not add Event foreign keys, shared aggregates, lifecycle coupling, or calls into Event ticket models.
- Use existing `User` records only for organizer and staff access. Use the dedicated Festival guard for registrants and guest judges.
- Require an active edition assignment for a staff or guest judge unless a Festival manager is acting through an explicitly authorized management flow.
- Keep `accounts.enable_festivals` disabled by default and enforce it at every Festival entrypoint.
- Treat `FestivalTariffPackage` as a one-time plan add-on and `FestivalEditionPurchase` as the only self-service entitlement for creating a new edition. Never fold Festival pricing into recurring location tiers.
- Only the studio owner may initiate a Festival purchase; Festival managers may redeem an already available entitlement. Zero-price packages grant access without a gateway payment or fiscal receipt.
- Use the required current tariff/package relationship as the source of truth for edition-purchase package and plan labels and participant/ticket quotas. Do not copy package configuration into purchases. Preserve the purchase's charged amount, currency, provider identifiers, callbacks, fiscal records, and other transaction facts.
- Count distinct participants only across submitted, under-review, and accepted performances. Count paid guest orders and unexpired pending holds, including free tickets. Lock the edition purchase row before enforcing either quota.
- A reversed redeemed purchase keeps all Festival data but makes that edition read-only until platform resolution.
- Reuse generic Ladna infrastructure only through Festival-owned actions and records. Payment gateways, storage, mail, queues, and secure QR design are reusable; Customer/Event domain models are not.
- Keep domain actions channel-neutral. A future Telegram bot or Mini App must adapt the same actions and attach Telegram identity to an existing account-scoped Festival portal user.

## Model Festival Work Correctly

- Treat `FestivalSeries` as reusable organizer and brand defaults, and `FestivalEdition` as one dated competition.
- Treat `FestivalEntry` as one performance containing one or more roster participants. Never model the participant as the application aggregate.
- Keep only the statuses required by current Festival screens and business rules. Reuse existing status fields before adding another status, coordination layer, or state machine.
- Derive readiness from all applicable gates. Do not persist a loosely synchronized ready flag or call query-producing readiness helpers inside collection loops.
- Read participant identity and age, category details, workflow configuration, requirement rules, and package names and quotas from current related records. Do not copy them into entries, pivots, progress rows, charges, purchases, adjustments, or results.
- Keep `FestivalEntryStep` and `FestivalEntryRequirement` as operational progress rows linked to their original workflow step and requirement definition. Never remap existing progress after a category selects another workflow. Added active steps, requirements, and fees initialize only for new entries; edits to already referenced workflow steps and requirement definitions apply immediately.
- Workflow steps and tariff packages referenced by runtime data may be edited or deactivated. Their restrictive foreign keys intentionally prevent deletion. Never replace that protection with synchronization, freezing, or copied fallback data.
- A requested correction edits the same current response and uses `correction_due_at`. Do not introduce response history, revision rows, or revision terminology.
- Update existing category, rule, price, requirement, upload, and rubric records through the current model. Prefer deactivation when deletion would break an active reference; do not create parallel versions, clones, revisions, or copied definition trees.
- Store entry submissions and payment proofs privately and serve them only through account- and role-authorized controllers.
- Keep participation charges separate from spectator admission orders and tickets.
- Reuse existing score submission and locking behavior where it exists. Do not add a separate unlock ledger or audit subsystem.
- Keep spreadsheet-style result administration source-based: score and comment cells write through the existing score-sheet action, penalty rows remain the only editable deductions, and totals, readiness, ties, and ranks stay derived rather than becoming a second result source of truth. Festival managers may inspect the full table, but only the account owner or an active category-scoped head judge may edit it.
- Model special nominations as edition-scoped definitions assigned directly to reusable `FestivalParticipant` performers. Preserve assigned definitions and participants, allow multiple performers per nomination, and expose only explicitly enabled nomination metadata in the public Mini App; never expose assigned performer names there.
- Keep public totals, ranks, and medals separate from private per-judge scores and comments.
- Preserve immutable transaction, audit, and security facts already required by existing flows: charged or paid amounts, currency, provider identifiers and callbacks, fiscal records, notification payloads, activity logs, private-file review state, QR scans, and published result totals and ranks. These are operational evidence, not configuration snapshots. Do not add generalized history infrastructure around them.

## Implement Through Festival-Owned Surfaces

1. Identify the affected existing models and the smallest current business flow before editing code.
2. Map account binding, guard, permission, edition assignment, and capability checks for every entrypoint.
3. Keep implementation under Festival-specific models, actions, requests, controllers, policies, routes, views, jobs, mail, and tables.
4. Use numeric model binding for staff administration and generated stable public slugs according to the lifecycle documented in the canonical model.
5. Authorize each staff workspace route on the server. Hiding a menu item is never authorization.
6. Query only data that the current permission and screen need. Do not expose applicant contact data to check-in or judge roles, or Festival revenue to non-finance roles.
7. Use transactions and row locks for inventory, scheduling collisions, payment state, score submission, and other concurrent mutations.
8. Reuse existing Ladna queue and notification patterns, including their current immutable delivery payloads. Do not add another delivery-history or configuration-copy layer.
9. When `.codex/docs/festivals/README.md` exists, update its affected sections and verification record without making application behavior or tests depend on that ignored local file.

## Activate Adjacent Skills Deliberately

- Use `laravel-best-practices` for Festival PHP, Laravel, database, routing, controller, policy, or test changes.
- Use `ladna-domain` only when the change also touches shared `Account`, `User`, SaaS-role, platform, or non-Festival tenancy behavior.
- Use `tailwindcss-development` for Festival Blade, sidebar, responsive layout, card, or theme work.
- Use `ladna-testing` for rendered desktop/mobile QA, screenshots, and browser-log inspection.
- Use `ladna-help-update` when owner-facing Festival behavior, navigation, or copy changes.
- Use `ladna-api-docs-update` only when a public Ladna API contract changes. Do not add a public Festival JSON API unless the product explicitly enters the Telegram/Mini App phase.
- Do not activate `ladna-versioning` or change `VERSION` and changelog metadata for Festival work. Use `ladna-production-deploy` only when deployment is explicitly requested.

## Verify The Boundary And Behavior

- Prove cross-account access fails for staff, portal, files, judges, callbacks, orders, tickets, and scanners.
- Prove Festival workflows neither create nor require `Customer` or `Event` records. Keep the Festival architecture-boundary test current.
- Cover every changed business transition, authorization branch, concurrency rule, and public/private visibility boundary. Do not add new snapshot, revision, or version behavior. When a task explicitly removes a legacy structure, test its forward-only data migration and assert the obsolete columns, tables, routes, and classes are absent.
- Use the configured dedicated test database and the repository's transactional PHPUnit convention. Never rebuild the development database.
- Run the singular changed test, related Festival suites, unchanged Event regression suites, and the complete compact suite in proportion to the change.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes and `npm run build` after frontend changes.
- Complete authenticated desktop and mobile rendered QA for interface changes, inspect screenshots visually, and check recent browser logs.
