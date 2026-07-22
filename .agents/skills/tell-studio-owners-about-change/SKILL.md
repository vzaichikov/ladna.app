---
name: tell-studio-owners-about-change
description: Use when the user explicitly asks to "tell customers about change", notify customers about a deployed Ladna change, or announce a Ladna update to studio owners. Sends through the Ladna platform owner bot only to current studio owners with eligible bot subscriptions; do not use for studio customers, staff, personal Telegram messages, drafts, or ordinary deploy summaries.
---

# Tell Studio Owners About Change

## Workflow

1. Confirm the described customer-facing change is deployed to production and identify the exact production Git SHA. Never deploy implicitly. If deployment is not proven, stop without sending.
2. Collect only confirmed user-facing facts. Draft one professional Ukrainian message and one equivalent English message. Explain what changed, where it is available, and any action the owner should take.
3. Exclude implementation details, tests, migrations, commit hashes, secrets, deploy logs, and the informal Slastya/Hobotun introduction. Use plain text and keep each message within 4096 characters.
4. Save the two drafts to temporary local files outside tracked application source.
5. Preview the exact production audience:

   ```bash
   .agents/skills/tell-studio-owners-about-change/scripts/announce-production.sh \
     --preview \
     --uk-file /absolute/path/message-uk.txt \
     --en-file /absolute/path/message-en.txt
   ```

6. Read the JSON result. Stop before sending if `ok` is false, the audience is empty, an integrity error exists, or the production/source facts are unexpected. Report eligible chat and language counts in commentary without exposing personal data.
7. Treat the user's explicit send wording as authorization. Do not ask for a second confirmation. Execute immediately with the previewed audience hash:

   ```bash
   .agents/skills/tell-studio-owners-about-change/scripts/announce-production.sh \
     --execute \
     --expected-audience-hash AUDIENCE_HASH \
     --uk-file /absolute/path/message-uk.txt \
     --en-file /absolute/path/message-en.txt
   ```

8. Re-run preview with the same files to monitor campaign statuses. Report sent, pending/processing, and failed counts truthfully; never claim complete delivery while retries remain.
9. Remove temporary message files after the campaign reaches a terminal state.

## Delivery Boundaries

- Invoke the application only through this skill's production wrapper. It supplies the process-only `codex_skill` execution origin; never expose the command or its services through an HTTP route, API, MCP tool, controller, or request parameter.
- A human platform owner may invoke the command only from a trusted CLI process with `LADNA_OWNER_ANNOUNCEMENT_ORIGIN=platform_owner` and `LADNA_OWNER_ANNOUNCEMENT_PLATFORM_USER_ID=<platform_admin user id>`. Do not persist either execution value in production `.env`.
- Use only the Ladna platform owner bot and its encrypted application credential. Never copy the token to source, skill files, command output, or a second `.env` value.
- Let the application command enforce active live studios, owner membership, bot authorization, phone fallback, disabled-alert exclusion, chat deduplication, audience hashing, and delivery idempotency.
- Do not use Telegram MCP or a personal Telegram session for this workflow.
- Route English only for a selected studio whose `default_language` is `en`; use Ukrainian for every other language.
- If any recipient identity is ambiguous or changed after preview, stop the entire broadcast.
