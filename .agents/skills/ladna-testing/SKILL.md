---
name: ladna-testing
description: Use when running Ladna manual QA, browser or Playwright checks, screenshot capture, or rendered UI verification in this Laravel app.
---

# Ladna Testing

## Demo Credentials And Data

- Use the canonical demo users and demo studio from the local `.env` file for manual QA:
  - `LADNA_DEMO_PLATFORM_*` for the product owner / platform admin.
  - `LADNA_DEMO_OWNER_*` for the demo studio owner.
- Do not create replacement product-owner or studio-owner login users for manual QA when these `.env` demo users are available.
- Never delete the demo product owner, demo studio owner, or demo studio as part of QA cleanup.
- If demo users or the demo studio are missing or stale, restore them with `php artisan db:seed --no-interaction` instead of recreating them manually.
- If a task truly needs an additional QA studio, create it without deleting or replacing the `.env` demo users; continue using the canonical demo accounts for login whenever possible.
- Prefer creating temporary business data inside the demo studio for the target flow, not new CRM login users.

## Browser QA

- Use `https://ladna.local/` and resolve URLs with Boost `get-absolute-url` before sharing or testing routes.
- Run Playwright through `.codex` from the project root so browser binaries stay outside root dependencies.
- Store screenshots, traces, and temporary QA scripts under `.codex/output`.
- Capture at least desktop and one mobile viewport when the UI surface is responsive.
- Inspect screenshots visually and check recent browser logs or Playwright console/page errors before reporting success.
