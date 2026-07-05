---
name: ladna-help-update
description: Use when updating Ladna public help for studio owners, including requests like "добавь это в хелп", "update help", documenting a new studio-owner workflow, adding screenshots, revising plain-language Ukrainian owner instructions, or keeping `/help` pages current after a feature change.
---

# Ladna Help Update

## Overview

Maintain the public `/help` section for studio owners. Keep it useful for a dance-studio owner, not for a developer: explain what to do in the studio, how screens connect, and what staff or clients will see.

## Workflow

1. Read `config/help.php`, `routes/web.php`, and the affected Blade/controller files for the feature being documented.
2. Activate and follow `ladna-domain` when the change touches studios, schedules, trainers, customers, bookings, class passes, public pages, payments, or website leads.
3. Update `config/help.php` first. It is the source of truth for help page titles, summaries, sections, steps, related pages, and screenshots.
4. Keep route/view structure stable unless the requested help change truly needs a new help page.
5. Add or replace desktop screenshots under `public/assets/help/screenshots/`.
6. Update tests in `tests/Feature/HelpPagesTest.php` when pages, required copy, or screenshot expectations change.
7. Run focused verification before finishing.

## Writing Rules

- Write the primary help copy in simple Ukrainian.
- Write for a studio owner or administrator who runs a dance studio.
- Prefer words from the UI and daily work: `студія`, `клієнт`, `заняття`, `запис`, `абонемент`, `прайс`, `тренер`, `зал`, `локація`.
- Avoid programmer and CRM slang in owner-facing copy: do not say `tenant`, `endpoint`, `payload`, `Bearer`, `database`, or `CRM`.
- If a technical concept must be mentioned, translate it into a practical action first, such as `ключ для форми сайту` instead of raw token terminology.
- Explain relationships by business flow: brand and locations -> rooms and directions -> formats -> schedule -> classes -> bookings -> passes -> public pages.
- Keep steps concrete and short. Each step should start with an action the owner can perform on screen.

## Business Case Pages

Use this pattern when the user asks to document real trainer/studio-owner questions such as `що робити, якщо ...`.

- Keep the chapter menu-structured: one concrete question gets one help page with its own `/help/{slug}` route.
- If the question belongs to a recurring case chapter, create or reuse a parent page and mark child pages with `parent`.
- Phrase the visible title as the owner-facing question, but translate studio shorthand into product terms: `дівчата` means `клієнти`.
- Put the original trainer wording in `questions` or `keywords` so the owner assistant and MCP help search can find it.
- Each answer page must include:
  - `Шлях у Ladna: ...` with visible screen/button names.
  - Numbered steps in the order a trainer or owner should perform them.
  - `business_logic` explaining what changes automatically, for example booking status, pass reservation/use, warnings, payments, or correction history.
  - Relevant screenshots from `public/assets/help/screenshots/`, or new screenshots if existing ones would mislead.
- For assistant coverage, update or add a focused test proving `OwnerHelpIndex`, `StudioAiInference`, or MCP `search-owner-help` returns the new page for the real wording.

## Screenshots

- Use Charmpole demo data unless the user explicitly asks for another studio.
- Capture desktop screenshots from `https://ladna.local/` at `1440x1000`.
- Use `.codex` Playwright commands from the project root so browser binaries stay outside app dependencies.
- Store temporary auth state and QA screenshots in `.codex/output`.
- Store final help images in `public/assets/help/screenshots/`.
- Do not expose secrets, raw access tokens, private credentials, or production-only data in screenshots.
- Replace screenshots when the visible UI changed enough that the old image would mislead a studio owner.

## Common Commands

```bash
php artisan route:list --path=help --except-vendor
php artisan test --compact tests/Feature/HelpPagesTest.php
vendor/bin/pint --dirty --format agent
npm run build
npm --prefix .codex run screenshot:desktop -- https://ladna.local/help .codex/output/help-index-desktop.png
```

Use Boost `get-absolute-url` before sharing or testing help URLs, and Boost `browser-logs` after browser QA when relevant.

## Versioning

If the help update is committed, use `ladna-versioning`: update `VERSION` and `config/changelog.php` with the correct SemVer impact and bilingual changelog entry.

Changelog entries are for studio owners and other Ladna clients. Mention only the visible feature or workflow improvement they can use; do not include internal cleanup, implementation details, deleted helper commands, refactors, or service notes.
