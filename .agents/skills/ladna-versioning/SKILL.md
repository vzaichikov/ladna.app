---
name: ladna-versioning
description: Use before every commit or push in this Ladna repository, and whenever changing the root VERSION file or Laravel changelog pages.
---

# Ladna Versioning

## Required Files

- `VERSION` at the repository root contains the current application version only.
- `config/changelog.php` contains the bilingual release history rendered by `/changelog.en.html` and `/changelog.ua.html`.
- The footer reads the same root `VERSION` value through `App\Support\ApplicationVersion`.

## Commit And Push Workflow

Before creating a commit or pushing commits:

1. Inspect the intended diff and identify the release impact.
2. Open `VERSION` and `config/changelog.php`.
3. Increment the version with SemVer judgment:
   - Major: breaking production behavior, incompatible API/data changes, or an explicitly approved stable release boundary.
   - Minor: user-visible features, workflow changes, meaningful UX changes, or large internal changes that alter behavior.
   - Patch: bug fixes, copy changes, dependency maintenance, tests, refactors without intended behavior change, or documentation.
4. While the app is pre-`1.0.0`, keep the first number at `0` unless the user explicitly approves a `1.0.0` release. Use the middle number for feature-level changes and the rightmost number for fixes or maintenance.
5. Add a top changelog entry for both locales with the new version, date, concise release notes, and the related commit hash when it is known. If preparing the changelog before the commit exists, describe the pending commit plainly and do not invent a hash.
6. Verify that `VERSION` exactly matches the newest changelog version.
7. If pushing, confirm the version and changelog changes are included in the commit set being pushed.

Do not skip versioning for small commits. If a commit is intentionally excluded from the public changelog, state the reason before committing or pushing.
