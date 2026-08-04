---
name: ladna-multi-location
description: Use when adding or reviewing Ladna pages, forms, dashboards, quick actions, class-pass availability, or reports that may change behavior based on a studio Location or the global working-location selector.
---

# Ladna Multi-location

## Purpose

Keep multi-location behavior predictable across Ladna. A selected working location is a user preference and a default scope, not an authorization boundary or an invisible global model scope.

Activate `ladna-domain` with this skill. Activate `ladna-testing` for rendered UI or browser verification and `laravel-best-practices` for PHP or Laravel changes.

## Inspect First

Read these files before extending the feature:

- `app/Support/WorkingLocationContext.php`
- `app/Http/Controllers/WorkingLocationController.php`
- `app/Http/Requests/UpdateWorkingLocationRequest.php`
- `resources/views/layouts/app.blade.php`
- `tests/Feature/WorkingLocationContextTest.php`

Inspect one analogous page before implementing a new integration. Prefer an existing single-select list, the scheduled-class multi-select filter, an account-wide scope badge, or the financial location comparison as the reference pattern.

## Context Contract

- `all` means all studio locations. A concrete value is an active location belonging to the current account.
- Persistence is account-specific and encrypted in a cookie. Never share a context value between accounts.
- A valid `location_context` query value overrides and refreshes the saved preference. Invalid, inactive, stale, or foreign values normalize to `all`.
- The global selector lists active locations only. Historical pages may still expose inactive locations locally.
- Switching the global selector may clear conflicting page-level location filters and pagination, but must preserve safe unrelated query state.
- Do not use a global Eloquent scope for working location. Apply context deliberately only where the domain says it is relevant.
- Do not use working location as proof of tenancy, permission, or ownership. Continue account-scoped validation and authorization.

## Scope Classification

Classify the entity before changing queries or UI.

Account-wide entities stay visible regardless of context and should say so when ambiguity is likely:

- customers;
- class types and activity directions;
- trainer types;
- class-pass segments.

Location-owned operational entities normally follow a concrete context:

- rooms and service rooms;
- schedule series and scheduled classes;
- studio events;
- location-specific cash, expense, payment, and operational report rows.

Mixed entities need explicit semantics:

- A `ClassPassPlan` with no rooms is available at all locations. A plan with rooms is available only at the locations of those rooms.
- A purchased class pass location or payment location is provenance. Do not treat it as the pass's usability boundary unless a separate business rule explicitly says so.
- Dashboard customer and lead totals are account-wide; schedule, room, substitution, and people-counter operations may follow the selected location. Label this mixed scope.

If the correct classification is unclear, inspect models, validation, public behavior, and existing tests before deciding. Do not infer location ownership from the presence of a nullable `location_id` alone.

## Precedence Rules

For list and report filters:

1. Respect an explicit page query, including an explicit blank value meaning All.
2. Otherwise default to the concrete working location.
3. Otherwise show all allowed locations.

For create forms:

1. Preserve `old()` input after validation failure.
2. Use an explicit page input when the workflow supplies one.
3. Use the concrete working location.
4. If exactly one active location exists, use it.
5. Otherwise leave the required choice blank.

For edit forms, the stored model value outranks the working context. Include the currently assigned inactive location so the form remains truthful and saveable.

## Filter Patterns

- Use `WorkingLocationContext::filterLocationId()` for single-select pages. It distinguishes a missing query key from an explicit blank All value.
- For checkbox or multi-select filters, include a submission sentinel such as `filters_submitted=1`. Without it, an empty submitted selection is indistinguishable from first page load.
- Preserve an explicit page-level All selection across tabs and pagination. Do not silently reapply the cookie when navigating within the page.
- Keep inactive locations available on historical filters and label them inactive.
- A Reset action normally returns to the current global context; users can submit explicit All when they want a page-level override.

## Reports and Comparisons

Treat comparison as a distinct report view, not a location dropdown hack.

- Reuse the same underlying payment, refund, expense, and result definitions as the summary.
- Include inactive locations for historical periods.
- Include a clear Unassigned row when source data can have no location.
- Keep currencies separate.
- Reconcile the comparison total exactly with the unfiltered report total.
- Do not mix owner deposits or withdrawals into operating result if the summary excludes them.

## Verification

Add focused PHPUnit coverage with `DatabaseTransactions` and the dedicated test database. Cover, as relevant:

- account-specific cookie persistence and safe redirects;
- inactive and foreign location rejection;
- page filters defaulting from context;
- explicit All overriding context;
- create defaults and edit preservation;
- account-wide vs location-specific entity visibility;
- multi-currency comparison rows, inactive locations, Unassigned rows, and total reconciliation.

Run the existing tests for every touched workflow. Then use the Ladna Playwright setup to inspect desktop and mobile rendering with four or five locations. Verify selector truncation, current selection clarity, page-filter consistency, empty states, and browser console errors.

## Completion Checklist

- The page's domain scope is classified and visible to the user when ambiguous.
- Explicit page filters override the global preference.
- All remains a real, reachable choice.
- Active-only global selection does not erase historical or edit access to inactive data.
- Tenant validation and permissions remain independent of context.
- Quick actions and create forms use the same default as their parent page.
- Tabs, reset links, and pagination preserve the intended scope.
- Relevant tests, formatting, frontend build, and rendered QA pass.
