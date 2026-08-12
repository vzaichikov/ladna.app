---
name: ladna-festival-landing-design
description: Use when adding, changing, designing, reviewing, or fixing a Ladna Festival landing template or predefined Festival colour palette, including template thumbnails, semantic palette tokens, public Festival Blade layouts, platform template grants, studio Branding choices, responsive visual QA, or requests such as "add/change festival template" and "add/change festival colour palette".
---

# Ladna Festival Landing Design

Create configurable Festival landing presentation without introducing editable template storage or changing Festival business logic.

## Load The Current Contract

1. Activate `ladna-festival-domain`, `ladna-brand`, and `tailwindcss-development`. Activate `laravel-best-practices` when PHP, requests, controllers, the resolver, or tests change, and `ladna-testing` for rendered QA.
2. If `.codex/docs/festivals/README.md` exists, read it completely, as required by the Festival domain skill.
3. Inspect these current sources before editing:
   - `config/festival_landing.php`
   - `app/Support/Festivals/FestivalLandingRegistry.php`
   - `resources/views/layouts/festival-public.blade.php`
   - `resources/views/festivals/public/templates/general.blade.php`
   - `resources/views/components/festivals/landing-template-card.blade.php`
   - the Festival palette rules in `resources/css/app.css`
   - `tests/Feature/FestivalLandingBrandingTest.php`
   - `tests/Feature/StudioPossibilitiesTest.php`

Treat `general` as the permanent compatibility template and palette. Preserve its behavior unless the user explicitly requests a change to General.

## Protect The Framework

- Keep templates and palettes code-backed in `config/festival_landing.php`; do not add template tables, editors, revisions, snapshots, or uploaded Blade/CSS.
- Keep config values serializable. Store translation keys, trusted Blade names, public thumbnail paths, swatches, and uppercase six-digit hex tokens only. Do not call `__()`, `asset()`, or `view()` in config.
- Never construct a Blade path, CSS value, or asset path from request or database input. Extend the resolver whitelist when the registry contract changes.
- Do not add migrations for a new template or palette. Existing edition keys and account grants already provide selection and entitlement.
- Keep `general` always available. Preserve saved revoked template keys so public rendering falls back immediately and restores automatically after a re-grant.
- Keep palette fallback independent from template entitlement.
- Do not move Festival rules, ticketing, authentication, judging, results, or registration logic into a template. Prepare data in the controller or a Festival-owned action.
- Keep the shared studio/Ladna-powered footer in `layouts.festival-public`. Do not render the global Ladna legal/version application footer on Festival pages.

## Add Or Change A Template

1. Choose a stable snake-case key. Never rename a shipped key; add a replacement and preserve fallback compatibility instead.
2. Design for configurable festival content rather than one discipline, image, language, or studio. Handle missing optional hero, description, sections, map, admission products, and results gracefully.
3. Create a trusted view under `resources/views/festivals/public/templates/` that extends `layouts.festival-public`.
4. Preserve complete public feature parity for every interchangeable template unless the user explicitly changes the product contract:
   - studio logo and identity;
   - Festival hero, title, date, time, venue, and description;
   - participant and judge entry points;
   - rules and organizer content sections;
   - ticket purchase and payment-provider selection;
   - published results;
   - shared Festival footer;
   - the same empty and unavailable states as General.
5. Escape ordinary content with `{{ }}`. Render only fields already sanitized by the existing rich-text pipeline with `{!! !!}`. For a presentation-only template, preserve General's current admission pricing and remaining-quantity calls rather than expanding scope into application logic. When logic changes are authorized, precompute query-producing presentation data once in the public controller or a Festival-owned action instead of multiplying queries in Blade.
6. Use semantic Festival classes and CSS variables. Keep CSS and JavaScript out of Blade. Scope template-specific CSS under `.festival-theme[data-festival-template='stable_key']` so it cannot alter General or another template. Avoid palette-specific hard-coded foregrounds and surfaces in template markup.
7. Add the registry entry with its key, bilingual name key, fixed view, and committed thumbnail.
8. Add an accurate tracked/static 16:9 WebP, PNG, or JPEG thumbnail under `public/assets/festivals/landing-templates/`. Capture the real rendered template; do not use unrelated concept art.
9. Add matching English and Ukrainian translations.
10. Verify that the platform card lists the template, General remains checked and permanent, only granted templates appear to studios, and public fallback/restoration does not rewrite the edition.

## Add Or Change A Palette

1. Choose a stable snake-case key and a clear translated name.
2. Define non-empty representative swatches and every semantic token in this exact order:
   - `page`
   - `surface`
   - `text`
   - `muted_text`
   - `primary`
   - `primary_text`
   - `accent`
   - `accent_text`
   - `border`
3. Add the matching static selector in `resources/css/app.css`:

   ```css
   .festival-theme[data-festival-palette='stable_key'] {
       --festival-page: #000000;
       /* Define all nine semantic variables. */
   }
   ```

4. Keep config hex values uppercase and stylesheet values lowercase so registry and CSS tests remain exact and readable.
5. Check text/background contrast rather than judging swatches visually. Target WCAG AA: at least 4.5:1 for normal text, 3:1 for large text and meaningful controls, and visible focus, borders, disabled states, errors, and links.
6. Exercise every semantic surface: page, cards, prose, fields and placeholders, primary/secondary/ghost buttons, ticket controls, results, and the powered footer. Check hover and focus states because Tailwind utility layers can override component rules.
7. Add English and Ukrainian names, and keep unknown stored palette keys falling back to `general` without persistence changes.

## Apply Design Quality

- Establish an obvious first-viewport hierarchy: studio identity, Festival identity, hero, essential date/place information, and primary actions.
- Keep the Festival visually distinct from the studio landing page while preserving trustworthy studio and Ladna provenance.
- Reuse the Ladna warmth and polish where appropriate, but let each registered template have a deliberate, recognizable composition.
- Make the primary participant action visually dominant and keep judge access clearly available without competing with purchase or application actions.
- Use responsive layouts that work at 390 px and desktop widths without horizontal overflow, clipped controls, or illegible hero crops.
- Avoid generic template decoration, excessive gradients, neon without purpose, low-contrast muted text, fixed-height content areas, and imagery containing baked-in interface text.
- Keep translated copy expansion, long Festival names, long venues, absent media, and rich text in the design test matrix.

## Verify Before Handoff

1. Extend registry tests for trusted views, unique keys, complete tokens, thumbnail existence and 16:9 dimensions, translation presence, and CSS selectors. Update the test's exact expected template or palette key list whenever a registered key is added.
2. Cover platform grants, studio availability, invalid keys, fallback after revocation, restoration after re-grant, stale keys, and isolation from unrelated settings.
3. Prove every new template renders the studio identity, hero, date/place, both portal links, rules/sections, ticket form and provider selection, results, empty states, and shared powered footer. Also prove General retains that contract while the global legal/version application footer is absent.
4. Run focused Festival branding and Studio possibilities tests on the configured dedicated test database. Run related Festival and Event regressions when template markup or public rendering changes.
5. Run Pint after PHP changes, Blade compilation, translation parity, `git diff --check`, and `npm run build` after CSS or Blade changes.
6. Render desktop and mobile screenshots for the template with a light and dark palette where supported. Inspect the screenshots and recent browser logs; do not infer visual correctness from tests alone.
7. Restore any temporary local edition palette, template, grant, or timestamp exactly after QA.
8. Update the affected branding and verification sections in `.codex/docs/festivals/README.md`.
