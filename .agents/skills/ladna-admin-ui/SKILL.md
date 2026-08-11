---
name: ladna-admin-ui
description: Use for authenticated Ladna Laravel Blade administration UI work involving CRUD indexes, filters, list or card views, create and edit forms, page headers, hierarchy kickers, back links, breadcrumbs, or app-layout navigation. Apply when creating or refactoring studio, Festival, report, or platform pages so Ladna uses separate create/edit routes, accessible icon actions, and complete clickable breadcrumb trails.
---

# Ladna Admin UI

Build authenticated Ladna pages as one coherent administration interface. Preserve domain authorization, tenancy, history, and existing business actions while standardizing navigation and presentation.

## Choose The Relevant Contract

- Read [references/crud.md](references/crud.md) completely for any resource list, filter, create, edit, activate, deactivate, reorder, or delete work.
- Read [references/breadcrumbs.md](references/breadcrumbs.md) completely for any `layouts.app` page, page heading, hierarchy kicker, back link, or breadcrumb work.
- Read both references when a CRUD route or page hierarchy changes.

## Work From Existing Ladna Primitives

1. Inspect sibling controllers, Form Requests, views, translations, tests, and `x-ui` components before editing.
2. Reuse named routes, route model binding, Form Requests, `x-ui.button`, `x-ui.action-button`, `x-ui.panel`, `x-ui.empty-state`, `crm-row`, `crm-field`, and the shared page-header, filter-bar, and breadcrumb components.
3. Keep all server authorization and tenant assertions. Treat hidden navigation as presentation only.
4. Keep organizer- or owner-facing copy translated in `lang/en/app.php` and `lang/uk/app.php` with key parity.
5. Test the changed routes, negative authorization and tenancy paths, Blade compilation, responsive rendering, and accessible labels.

## Protect Business History

- Do not invent destructive deletion when records may be referenced by bookings, payments, Festival entries, schedules, scores, notifications, or audit history.
- Prefer active/inactive lifecycle actions when the domain already preserves historical records.
- Keep quick state and ordering actions atomic and server-authorized.

## Coordinate Adjacent Ladna Skills

- Use `ladna-domain` for shared account, role, permission, or tenancy behavior.
- Use `ladna-festival-domain` for Festival resources and interfaces.
- Use `laravel-best-practices` for controllers, routes, queries, Form Requests, composers, and tests.
- Use `tailwindcss-development` for Blade layout and responsive styling.
- Use `ladna-testing` for rendered desktop/mobile QA.
- Use `ladna-help-update` when owner-facing navigation or workflows change.
