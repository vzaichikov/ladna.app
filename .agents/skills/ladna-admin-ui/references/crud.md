# CRUD Standard

## Required Page Model

Represent each managed resource with these routes and pages:

1. An index page containing the resource title, explanation, filters, list, and Add button.
2. A dedicated create page containing only the create form and its context.
3. A dedicated edit page containing only the edit form and its context.

Never place create or edit forms inline on an index page. Do not use hidden edit panels, expandable create details, edit modals, or JavaScript toggles as substitutes for dedicated routes. A composite settings page may remain an overview, but each distinct resource must link to its own index.

## Index Header And Add Action

- Render the standard page header with one clear `h1` and optional explanatory copy.
- Put a primary Add action at the header edge. Include a plus icon and visible localized text.
- Do not repeat breadcrumb hierarchy in a kicker or back link.
- Preserve genuine metadata such as a Series name, status, scope badge, or date when it adds information rather than hierarchy.

## Filters And Pagination

- Use a server-rendered GET form through the shared filter-bar component.
- Provide a name/text filter and add only high-value resource keys such as status, type, category, workflow, visibility, or scope.
- Normalize text filters with `$request->string('q')->trim()` or the established local equivalent.
- Apply filters inside the tenant- and parent-scoped Eloquent query.
- Order deterministically by the domain order, then `id`; otherwise use the established page order.
- Paginate indexes at 20 records and call `withQueryString()`.
- Keep an explicit Reset link to the unfiltered named index route.
- Distinguish “no records yet” from “no records match these filters” and give the filtered state a reset path.

## List Presentation

Choose the smallest useful presentation:

- Use compact `crm-row`-style rows for records whose identity and a few attributes are enough.
- Use cards for records needing rich metadata, badges, previews, or several related facts.
- Keep the resource name as the strongest text and place status plus important relationships nearby.
- Keep action controls right-aligned on wide screens and wrapped without horizontal page overflow on mobile.
- Escape ordinary text with Blade `{{ }}`. Render HTML only after the domain sanitizer has produced trusted content.

## Row Actions

- Use `x-ui.action-button` for icon-only row actions and supply localized `title`, `aria-label`, and screen-reader text through its `label` prop.
- Link Edit to the dedicated edit route.
- Keep activate/deactivate and ordering as small POST/PATCH forms with CSRF protection and server authorization.
- Disable impossible first/last moves.
- Hide ordering controls whenever filters conceal records from the active ordering scope. A relationship filter may define an explicit ordering scope only when the endpoint validates that scope and preserves the positions of records outside it; keep ordering hidden when any additional filter conceals peers inside that scope.
- Do not add Delete unless the existing domain explicitly supports safe deletion. Preserve deactivation and history rules.

## Create And Edit Forms

- Share field markup between create and edit when practical, but render it only on the dedicated page.
- Use the existing Form Request and only validated data.
- Display field-level validation messages and preserve `old()` values.
- Keep automatic internal codes, slugs, stable identifiers, and snapshots hidden unless users genuinely manage them.
- Provide a primary Save/Create action and a secondary Cancel action back to the relevant index.
- Redirect successful create/update actions to the relevant index with a translated status message unless the established workflow requires returning to the record.
- Let failed validation return to the create/edit page Laravel already knows from the referrer.

## Query And Domain Safety

- Bind resources through named, scoped routes and verify every account, parent, and edition boundary.
- Eager-load relationships and counts rendered by the list. Never query from Blade.
- Preserve permissions independently for index, create/edit, state actions, and finance-only resources.
- Preserve stable generated identifiers, timezone conversion, sanitization, file replacement cleanup, immutable snapshots, and dependency-based deactivation blocks.

## Acceptance Checklist

- Index has Add, filters, reset, list/card rows, accessible icon actions, empty states, and pagination.
- Create and edit have unique GET routes and no inline equivalent remains.
- Filter query strings survive pagination.
- Reorder controls disappear under filters and impossible moves are disabled.
- Authorization and cross-tenant/parent failures are tested.
- The page works at desktop and mobile widths without horizontal page overflow.
