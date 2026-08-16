---
name: ladna-payment-checkout-ui
description: Standardize Ladna public payment, ticket checkout, and ticket delivery presentation across Event tickets, Festival admission, class-pass purchases, and Festival charges. Use when adding, changing, reviewing, or fixing responsive checkout layouts, provider buttons, Monopay ticket iframes, card and wallet marks, agreements, payment errors, private ticket returns, QR tables, PDF sharing, printing, or payment-status states.
---

# Ladna Payment Checkout UI

## Workflow

1. Activate the domain skill for the flow being changed. Also activate `tailwindcss-development` for Blade layout work, `laravel-best-practices` for PHP or validation changes, and `ladna-testing` for rendered QA.
2. Inspect the current public class-pass checkout and the canonical Festival charge card before editing. Preserve their established customer-facing composition unless the request explicitly changes the standard.
3. Reuse `x-ui.payment-brand`, `x-ui.accepted-card-brands`, `x-ui.button`, existing public legal routes, and account-scoped enabled payment settings.
4. Keep business actions, requests, models, callback handling, and payment statuses owned by their Event, class-pass, or Festival domain. Share presentation primitives, not payment state machines.
5. Cover the rendered contract with feature tests and desktop plus mobile browser QA.

## Presentation Contract

- Use a stacked mobile layout and a two-column desktop layout: purchase explanation or buyer inputs on the left, payment on the right.
- Keep one parent form when buyer inputs and provider actions submit one payload. Never nest forms.
- Put the contextual agreement immediately before the payment action. Link the exact rules, offer, or terms accepted by that flow and retain its domain-specific checkbox name.
- Render one clear submit action per available provider. Use the emerald success button treatment and `presentation="card"` for `x-ui.payment-brand` so supported wallet marks remain consistent.
- For Monopay card actions, visibly render the Google Pay and Apple Pay marks inside the action exactly as the public class-pass checkout does.
- Render Visa and Mastercard through `x-ui.accepted-card-brands` below a divider whenever paid card payment is available.
- In checkouts where the customer must select an item first, keep configured paid-provider actions and their wallet/card marks visible but disabled before selection. Hide them only when the current selection is free. Never render payment marks for a provider the account has not configured.
- Keep payment and free-checkout submit actions disabled until every required buyer field and contextual agreement is complete and valid. Update this state as fields change while retaining server validation as authority.
- Place every blocking or help message immediately above the action it explains. Keep the action label stable; do not replace it with instructional copy or rely on a browser-native validation tooltip as the only guidance.
- Keep providers account-scoped and credential-ready through the existing payment registry. Do not introduce a parallel provider configuration path.
- Handle no-selection, free, no-provider, pending, paid, failed, cancelled, and refund-required states explicitly. Do not present a browser return as confirmed payment before the persisted callback state is paid.
- On pending confirmation pages, keep a clear link back to the originating Event or sellable page so a guest can leave or start another checkout. Do not imply that returning edits the already-created pending order.
- Show provider and agreement validation errors inside the payment block without losing buyer input.
- Keep internal attempt, gateway payload, callback, queue, and retry implementation language out of customer-facing copy.

## Accessibility And Responsive Behavior

- Use semantic labels, real buttons, visible focus states, and accessible names for icon-only controls.
- Keep tap targets usable on mobile and prevent long provider or agreement text from overflowing.
- Preserve server-side validation as authoritative; client-side counters, totals, and disabled states are guidance only.
- Do not query payment or inventory data from Blade. Prepare display data in the controller or a dedicated support class.

## Monopay iFrame V2

- Keep Monopay ticket iframe checkout behind the platform-owned `payments.monopay.event_iframe_v2_enabled` compatibility key. Treat it as the shared Event-and-Festival-ticket presentation flag; disabled is the compatibility default and must preserve the existing external redirect.
- Apply the enabled flag only to customer-facing ticket purchases: Event tickets and Festival venue-admission tickets. Class-pass purchases, Festival participant/entry/step charges, Festival SaaS purchases, subscriptions, SMS, and every other payment form must remain external redirects.
- Keep studio Monopay credentials account-scoped. The platform flag chooses presentation only and must not become a parallel merchant-credential source.
- Create and persist the domain order before opening the iframe. Render the returned `pageUrl` only on the private account plus access-token payment route, and retain a resumable link from the pending order page.
- Render the same responsive iframe flow on mobile and desktop. Use the full available width with a sensible desktop maximum, keep enough vertical height for the checkout, and include `allow="payment *"`; never redirect only because the viewport is narrow.
- Once the Monopay iframe is loaded, treat it as the payment action. Do not repeat the emerald branded provider button above it; retain only a clearly worded separate-page fallback link and order navigation.
- Accept iframe messages only from the exact trusted Monobank checkout origin and the expected frame window. Handle `close-button` by returning to the private order and accept only the `monobank:` scheme for `monopay-link` deep links.
- Treat the signed server webhook and persisted order status as authoritative. Poll the private status endpoint and never issue tickets from a frame message or browser return.
- Keep a direct separate-page payment link as fallback, restrict `frame-src` to the trusted Monobank origin, and return private/no-store/no-referrer/nosniff headers on the iframe page.

## Ticket Return And Delivery

- Give Event and Festival venue-ticket orders a complete private return flow under the account plus access-token scope: pending confirmation, explicit terminal failure/refund states, and a paid ticket table with one valid ticket and QR per row.
- Poll pending orders every two seconds for no longer than 60 seconds, then reveal manual refresh. The signed server callback remains authoritative.
- Show QR, share, download, and print controls only for exact paid orders, valid entrance tickets, and a non-cancelled active surface. Online-stream Festival tickets remain in the Festival Guest cabinet and never enter an entrance-ticket PDF.
- Generate PDFs on demand without buyer PII or remote assets. Use the studio and Event/Festival name, localized date/time and venue, immutable ticket type snapshot, code, and QR; render exactly one ticket per A4 page.
- Native PDF sharing must fetch the protected PDF as a `File`, verify `navigator.canShare({ files })`, and use `navigator.share({ files })`; always retain direct download fallback and browser print/save-PDF.
- Apply private/no-store/no-referrer/nosniff headers to the private order, status, PDF, and QR responses. Scope every lookup by account and hashed access token, and never expose paid controls for failed, cancelled, expired, refund-required, refunded, voided, or archived tickets/orders.

## Verification

- Assert the provider action, contextual agreement, Google Pay and Apple Pay marks for Monopay, Visa/Mastercard footer, disabled pre-selection and incomplete-required-fields states, above-action blocking help, empty-provider state, and error state.
- Test free and paid paths separately, including multiple configured providers.
- Capture desktop and mobile screenshots and inspect recent browser logs.
- When shared primitives change, rerun the Event, class-pass purchase, and Festival payment UI tests without changing their domain behavior.
- For Monopay iframe v2, assert the platform-only shared ticket flag, disabled request without `displayType`, enabled Event and Festival admission requests with `displayType: iframe`, private route/token isolation, trusted origin and CSP, responsive mobile/desktop iframe and deep-link hooks, absence of a duplicate outer payment CTA, separate-page fallback wording, resume behavior, and unchanged class-pass, Festival participant/step, subscription, and other non-ticket payment requests.
- For ticket delivery, assert pending and every terminal status, tenant/token isolation, entrance-ticket filtering, private response headers, QR contents, PDF filename, no buyer PII, native-share download fallback hooks, and PDF page count equal to the number of valid printable tickets.
