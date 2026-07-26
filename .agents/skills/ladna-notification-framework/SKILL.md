---
name: ladna-notification-framework
description: Use when adding, changing, or reviewing Ladna notification channels, notification types, delivery scenarios, studio-owned notification switches, outbox records, senders, quiet-hour behavior, deduplication, or cancellation/restore handling for trainers and customers.
---

# Ladna Notification Framework

## Overview

Extend Ladna notifications through the existing tenant-scoped outbox architecture. Keep scenario ownership understandable for studio owners and make queueing, delivery, cancellation, retries, and production defaults safe.

Read [references/framework-map.md](references/framework-map.md) before changing notification behavior.

Also activate:

- `ladna-domain` for tenancy, roles, bookings, schedules, customers, and trainers.
- `laravel-best-practices` for Laravel implementation work.
- `tailwindcss-development` when changing the notification settings UI.
- `ladna-testing` for rendered settings QA or screenshots.
- `ladna-help-update` when owner-facing behavior or settings copy changes.
- `ladna-versioning` before committing or pushing.
- `ladna-production-deploy` only when deployment is explicitly requested.
- `notify-ladna-founders` only when the user explicitly requests an announcement after deployment.

## Workflow

1. Map every business entrypoint that produces the event. Prefer one existing action or coordinator hook over controller-specific hooks.
2. Identify the authoritative state and transaction boundary. Queue only after the business transaction commits.
3. Add a dedicated enum type, producer/queue class, renderer, and registry entry. Never call Telegram or SMS directly from booking or schedule actions.
4. Store a complete immutable payload for auditability, plus tenant and domain foreign keys where they remain valid.
5. Add a stable dedupe key matching the event’s intended frequency. Decide explicitly whether rebooking, restore/re-cancel, or a new episode should notify again.
6. Apply effective enablement twice:
   - Before creating an outbox row.
   - Immediately before the external request.
7. Revalidate current domain state before delivery. A restored class, reactivated booking, changed trainer, disabled studio, or read-only demo must stop stale delivery without deleting history.
8. Cancel or fail superseded pending rows with a clear machine-readable reason. Never rewrite sent or historical rows.
9. Add studio-owner switches and legends under `Налаштування сповіщень` → `Сценарії сповіщень`.
10. Add feature tests for queueing, delivery-time gates, dedupe, retries, restore/reversal, tenancy, authorization, and unaffected bypass flows.
11. Update help, screenshots, bilingual translations, release metadata, and production proof when those are in scope.

## Safety Rules

- Default a new scenario to `false` unless the user explicitly requires existing studios to start receiving it. Missing settings rows must resolve to the same safe default.
- Preserve existing master switches and scenario values during schema changes. Use schema-only migrations; do not backfill or mutate production rows unless explicitly required.
- Founders/platform announcements bypass studio scenario settings and must remain unchanged.
- Read-only demo studios never create or deliver external notifications.
- Customer delivery stays behind the platform-owned customer-notification capability and the studio-owned master/scenario switches.
- Quiet hours apply per notification type. Bypass them only for a product-approved urgent type, document that in the UI, and test a night-time send.
- For concurrent booking cancellation, lock the scheduled class before its bookings when deciding whether the last active booking disappeared.
- Do not cascade-delete audit/outbox history. Prefer nullable references and durable payload data.
- Do not silently add a new external provider, dependency, scheduler, or channel.

## Required Verification

- Run the singular new/updated PHPUnit tests, then related notification suites.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- Run the full compact test suite and `npm run build` before release handoff.
- Use Playwright for desktop and mobile settings QA; inspect captured images and recent browser logs.
- For production, prove pre/post studio switch values, provider configuration, outbox history counts, migration status, version, SHA parity, and maintenance mode.
