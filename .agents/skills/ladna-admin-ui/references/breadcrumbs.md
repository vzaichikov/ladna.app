# Breadcrumb Standard

## Scope And Contract

Apply breadcrumbs to every authenticated page rendered through `layouts.app`. Public pages, customer authentication, and Festival portal layouts keep their own navigation unless their scope is explicitly changed.

Pass an ordered array of items with this shape:

```php
[
    ['label' => __('app.workspace'), 'href' => route('dashboard.index')],
    ['label' => $account->name, 'href' => route('dashboard.accounts.show', $account)],
    ['label' => __('app.locations'), 'href' => route('dashboard.accounts.locations.index', $account)],
    ['label' => __('app.edit').' '.$location->name],
]
```

- Give every ancestor an `href` generated from a named route.
- Leave the final item without `href` and render it with `aria-current="page"`.
- Escape every label.
- Keep the final crumb specific enough to distinguish index, create, edit, detail, report, and nested-resource pages.

## Resolution Architecture

- Resolve trails centrally through an application breadcrumb resolver and layout view composer.
- Define route families explicitly by named route. Do not infer hierarchy from URL segments.
- Use models already bound to the route. Do not issue database queries from the resolver, composer, component, or layout.
- Use explicit resource parameter and label definitions for nested entities.
- Fail loudly in development and tests when an authenticated app-layout route has no definition. Never restore the old inert two-item fallback.

## Hierarchy Rules

- Start studio routes with `Workspace`, then the current account.
- When a platform administrator opens an account-scoped route, retain the platform hierarchy (`System admin → Accounts → Account`) instead of sending them through the studio workspace.
- Start Festival administration routes with `Festivals`, then the edition when one is bound.
- Start platform administration routes with the platform dashboard, then the relevant collection and record.
- Include an index ancestor before create/edit/detail actions.
- Include intermediate overview resources for deep trails, such as `Settings` and `Content & media`.

Representative trails:

- `Workspace → Account → Locations → Edit location`
- `Festivals → Edition → Judging & results`
- `Festivals → Edition → Settings → Categories → Add category`
- `Festivals → Edition → Settings → Content & media → Documents → Edit document`

## Semantic And Responsive Rendering

- Render a `<nav aria-label="…"><ol>` structure.
- Mark separators as decorative and hide them from assistive technology.
- Keep breadcrumbs visible at mobile widths.
- Allow horizontal scrolling inside the breadcrumb container without making the page overflow.
- Truncate long labels visually while retaining the complete text in the DOM and in a `title` attribute.
- Preserve a visible focus state and a generous touch target for linked ancestors.

## Page-Heading Cleanup

- Keep one page `h1` below the global breadcrumb bar.
- Remove kickers, subheaders, and back links that merely repeat breadcrumb ancestors.
- Keep metadata kickers, scope badges, status labels, and explanatory copy when they convey information absent from the trail.
- Do not turn the current crumb into a second page heading.

## Acceptance Checklist

- Every ancestor is clickable and uses a named route.
- The last item is plain text with `aria-current="page"`.
- Labels are escaped and long labels remain accessible.
- Mobile breadcrumbs remain visible and scroll independently.
- Representative studio, nested report, Festival, and platform trails are tested for order and URLs.
- Automated coverage detects unmapped authenticated app-layout routes.
