---
name: ladna-api-docs-update
description: Use when changing Ladna public API routes, request payloads, response resources, authentication, rate limits, or code examples so the OpenAPI page stays accurate.
---

# Ladna API Docs Update

## Overview

Keep Ladna API behavior and documentation synchronized. Use this skill before editing `routes/api.php`, API controllers, API requests/resources, account API token auth, public schedule/price output, website lead intake, or API examples.

## Required Workflow

1. Inspect the changed API surface first:
   - `routes/api.php`
   - relevant controllers under `app/Http/Controllers/Api`
   - relevant requests under `app/Http/Requests/Api`
   - relevant resources under `app/Http/Resources`
   - `app/Support/OpenApi/LadnaOpenApiSpec.php`
2. Update `app/Support/OpenApi/LadnaOpenApiSpec.php` in the same change when any path, method, parameter, request body, response field, auth behavior, rate limit, or example changes.
3. Keep the HTML documentation page at `resources/views/api-docs/show.blade.php` generic. It should render from the spec/example data rather than duplicate endpoint definitions.
4. Add or update focused feature tests. At minimum, cover:
   - `/api-docs` renders the affected endpoint.
   - `/api-docs/openapi.json` contains the affected path, method, auth, and schema details.
   - the real API endpoint behavior still matches the documented happy path and validation/auth failure path.
5. Run the targeted tests for the changed API and docs, then run `vendor/bin/pint --dirty --format agent` if PHP changed.

## Conventions

- Public schedule and price endpoints stay unauthenticated unless a product decision says otherwise.
- Website lead creation uses an account bearer token created in My brand -> API.
- Token values are sensitive. Store searchable hashes and encrypted token values only; do not expose revoked tokens as active examples.
- Code examples should include PHP, Python, and JavaScript for each documented endpoint.
- Use placeholder slugs and tokens in docs, such as `charmpole`, `main-studio`, and `ladna_your_token`.
