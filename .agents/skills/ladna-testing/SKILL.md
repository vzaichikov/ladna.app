---
name: ladna-testing
description: Use when running Ladna manual QA, browser or Playwright checks, screenshot capture, or rendered UI verification in this Laravel app.
---

# Ladna Testing

## QA Identities And Data

- Use the configured `LADNA_PLATFORM_OWNER_*` identity for platform-level QA. Older local environments may still provide the temporary `LADNA_DEMO_PLATFORM_*` compatibility keys.
- Use `demo@ladna.app` with password `demo` only for read-only demo-studio navigation and presentation checks. Do not use that account for mutation-flow QA.
- Never run `db:seed` to create or restore QA studios. The public seeder is an explicit, empty-database platform bootstrap and contains no studio data.
- Never delete, replace, or regenerate Charmpole during QA cleanup. Treat it as protected existing studio data.
- Provision or repair the synthetic read-only demo only through its guarded command after verifying the exact environment and target account. Do not recreate it manually.
- For mutation-flow QA, use an authorized writable local studio and keep temporary records scoped to that studio.

## Browser QA

- Use `https://ladna.local/` and resolve URLs with Boost `get-absolute-url` before sharing or testing routes.
- Run Playwright through `.codex` from the project root so browser binaries stay outside root dependencies.
- Store screenshots, traces, and temporary QA scripts under `.codex/output`.
- Capture at least desktop and one mobile viewport when the UI surface is responsive.
- Inspect screenshots visually and check recent browser logs or Playwright console/page errors before reporting success.
