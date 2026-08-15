---
name: ladna-payment-checkout-ui
description: Standardize Ladna public payment and checkout presentation across Event tickets, class-pass purchases, and Festival charges or admission. Use when adding, changing, reviewing, or fixing responsive checkout layouts, provider buttons, card and wallet marks, agreements, payment errors, or payment-status states.
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
- Keep providers account-scoped and credential-ready through the existing payment registry. Do not introduce a parallel provider configuration path.
- Handle no-selection, free, no-provider, pending, paid, failed, cancelled, and refund-required states explicitly. Do not present a browser return as confirmed payment before the persisted callback state is paid.
- Show provider and agreement validation errors inside the payment block without losing buyer input.
- Keep internal attempt, gateway payload, callback, queue, and retry implementation language out of customer-facing copy.

## Accessibility And Responsive Behavior

- Use semantic labels, real buttons, visible focus states, and accessible names for icon-only controls.
- Keep tap targets usable on mobile and prevent long provider or agreement text from overflowing.
- Preserve server-side validation as authoritative; client-side counters, totals, and disabled states are guidance only.
- Do not query payment or inventory data from Blade. Prepare display data in the controller or a dedicated support class.

## Verification

- Assert the provider action, contextual agreement, Google Pay and Apple Pay marks for Monopay, Visa/Mastercard footer, disabled pre-selection state, empty-provider state, and error state.
- Test free and paid paths separately, including multiple configured providers.
- Capture desktop and mobile screenshots and inspect recent browser logs.
- When shared primitives change, rerun the Event, class-pass purchase, and Festival payment UI tests without changing their domain behavior.
