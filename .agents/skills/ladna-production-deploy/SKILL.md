---
name: ladna-production-deploy
description: Safely deploy the Ladna Laravel production app from this repository. Use when the user asks to deploy, publish, release, update production, run the production deploy, or check production deploy readiness for the Ladna app at the configured production SSH target.
---

# Ladna Production Deploy

## Core Rules

- Deploy only after the user explicitly asks for a production deploy.
- Never seed production data. Do not run `db:seed`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, or `db:wipe`.
- Use the local ignored connection files in `.codex/`; do not commit SSH host, key material, or generated deploy logs.
- Before committing or pushing, use `ladna-versioning` to decide whether release metadata is needed. Include `VERSION` plus `config/changelog.php` only when the change is client-facing and useful to users, studio owners, or clients. For patch-only bugfixes, internal maintenance, tests, refactors, deploy tooling, or cleanup that the user wants released quietly, leave release metadata unchanged and state the exclusion reason before committing.
- Prefer minimal, relevant local verification before production deploy. If PHP files changed, run Pint and the focused tests. If frontend assets changed, run `npm run build`.
- Treat production as mutable and high risk. Stop on unclear Git state, failed tests, failed build, failed SSH, dirty production worktree, branch mismatch, or any migration concern that needs human review.

## Standard Workflow

1. Inspect `git status --short` and the diff that will be deployed.
2. If a commit or push is needed, use `ladna-versioning`, decide whether the public changelog is useful for this change, run the relevant local checks, commit, and push.
3. Run the guarded dry run:

   ```bash
   .agents/skills/ladna-production-deploy/scripts/deploy-production.sh --dry-run
   ```

4. Review the dry-run output. It must show a clean local worktree, a pushed local commit, a clean production worktree, the expected production root, the production branch, current and target Git SHAs, and migration status.
5. Run the production deploy only when the dry run is clean:

   ```bash
   .agents/skills/ladna-production-deploy/scripts/deploy-production.sh --execute
   ```

6. Report the deployed branch, before/after SHAs, migration result, asset build result, and any warnings.

## What The Script Does

The script reads `.codex/production.env` and uses `.codex/production-ssh`.

In execute mode it:

1. verifies local Git is clean and pushed to its upstream;
2. verifies production is a clean Git checkout on the same branch;
3. fetches production `origin`;
4. puts Laravel into maintenance mode with a cleanup trap;
5. runs `git pull --ff-only`;
6. runs `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`;
7. runs `php artisan optimize:clear`;
8. runs `npm ci --include=dev --ignore-scripts`;
9. runs `npm run build`;
10. runs `php artisan migrate --force --no-interaction`;
11. runs `php artisan optimize`;
12. runs `php artisan queue:restart`;
13. brings the app back up.

If the deploy fails after maintenance mode starts, the script attempts `php artisan up` before exiting.
