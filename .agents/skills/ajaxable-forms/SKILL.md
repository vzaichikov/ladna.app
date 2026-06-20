---
name: ajaxable-forms
description: Use when making Ladna Laravel Blade forms save asynchronously without a full page reload. Applies to opt-in AJAX create, update, status-change, and delete forms that should reuse existing FormRequest validation, controller authorization, server-rendered Blade fragments, localized messages, and non-JavaScript redirect fallback behavior.
---

# Ajaxable Forms

## Core Rule

Make forms AJAX-saveable by opting in one form or screen at a time. Do not globally hijack all form submissions unless the user explicitly approves that larger behavior change.

The established pattern in this app is `form[data-async-form]` handled in `resources/js/app.js`. Keep normal browser submits working for users without JavaScript and for future rollback safety.

## Workflow

1. Read the target page, controller, FormRequest, route, Blade partials, and relevant tests before editing. Also follow the project Laravel Boost requirement to search version-specific docs before code changes.
2. Add `data-async-form` only to the forms the user asked to make asynchronous. Keep `action`, `method`, CSRF, spoofed methods, permissions, and fallback redirects intact.
3. For delete forms that already use `data-confirm-delete`, preserve that attribute. The async submit must run only after the existing confirmation flow marks the form confirmed.
4. Return JSON only for JSON/XHR requests. Keep redirect responses for normal requests.
5. Render updated UI from shared Blade partials or components. Do not build HTML strings in JavaScript.
6. Replace one stable fragment root per change, such as a row or card with a deterministic `data-*` selector. For scheduled classes, the current contract is `data-scheduled-class-card` plus `card_html`.
7. Reinitialize only fragment-local behavior after replacement, such as autocomplete, icon hydration, menus, or date controls. Initializers must be idempotent.
8. Keep authorization, tenancy, and record ownership checks on the server. Treat all IDs from forms or fragment attributes as untrusted input.

## Response Contract

Use a small JSON shape that tells the client what changed and supplies server-rendered markup:

```json
{
  "message": "Saved.",
  "resource_id": 123,
  "fragment_html": "<article ...>...</article>"
}
```

For an existing screen-specific helper, keep its current keys unless there is a reason to generalize. Scheduled classes already use `scheduled_class_id` and `card_html`.

Validation failures should return HTTP 422 with:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field": ["Localized validation message."]
  }
}
```

## JavaScript Contract

Submit `FormData` with:

- `Accept: application/json`
- `X-Requested-With: XMLHttpRequest`
- `credentials: 'same-origin'`

Do not set `Content-Type` manually for `FormData`.

While a request is pending, disable the submitting form's controls and show localized status. On success, replace the target fragment and show the returned message. On validation or server error, restore controls and show the useful error text without navigating away.

## Testing And QA

- Add or extend PHPUnit feature tests for JSON success, JSON validation failure, and the existing HTML redirect fallback.
- Assert database changes for create, update/status-change, and delete paths.
- Assert returned fragments contain the expected updated UI and omit deleted records where applicable.
- Run focused tests for the changed feature, then `vendor/bin/pint --dirty --format agent` for PHP edits and `npm run build` for frontend edits.
- For visible UI behavior, run Playwright from `.codex`, verify the URL does not change during AJAX saves, inspect the rendered result, and check recent browser logs when relevant.

## Avoid

- Do not add Livewire, htmx, Alpine plugins, or new dependencies just to make a form asynchronous.
- Do not duplicate Blade markup in JavaScript.
- Do not remove existing redirect flows.
- Do not make a broad framework-wide form interceptor unless the user explicitly requests and accepts the compatibility risk.
