---
name: notify-ladna-founders
description: Use when the user explicitly asks to notify Ladna Founders about abilities, news, or updates that are already deployed to Ladna production. Sends one Ukrainian announcement through the verified Ladna support bot destination. Do not trigger automatically after deploys, for drafts, for undeployed work, or for personal Telegram messages.
---

# Notify Ladna Founders

## Workflow

1. Confirm the announced behavior is deployed to production. Never deploy implicitly.
2. Collect only verified user-facing facts from the deployed production SHA.
3. Draft one professional Ukrainian message. Explain what changed, where it is available, and any action founders should take.
4. Exclude implementation details, tests, migrations, commit hashes, credentials, deploy logs, and the personal Slastya/Hobotun introduction. Keep the message within 4096 characters.
5. Save the draft to a temporary file outside tracked application source.
6. Preview the verified production destination:

   ```bash
   .agents/skills/notify-ladna-founders/scripts/announce-production.sh \
     --preview \
     --message-file /absolute/path/message-uk.txt
   ```

7. Stop without sending if the preview fails, the destination is not exactly `Ladna Founders`, the destination is disabled or unverified, the source SHA is unexpected, or the target hash is missing.
8. Treat the user's explicit notification wording as send authorization. Execute immediately without asking for a second confirmation:

   ```bash
   .agents/skills/notify-ladna-founders/scripts/announce-production.sh \
     --execute \
     --expected-target-hash TARGET_HASH \
     --message-file /absolute/path/message-uk.txt
   ```

9. Re-run preview with the same file until the campaign reaches a terminal status. Never claim delivery while it is pending or processing.
10. Remove the temporary message file after terminal delivery.

## Final Report

Report the verified group title and type, exact Ukrainian message, production source ref, campaign hash, and final `sent`, `pending`, `processing`, and `failed` totals.

Call the message delivered only when `sent` is one and every other status is zero. Otherwise, describe it as submitted or failed according to the final statuses.

## Boundaries

- Use only the configured and verified Ladna Founders target. Never accept or pass an arbitrary chat ID from the publishing request.
- Use the Ladna support bot credential already encrypted in the application. Never copy a token to source, skill files, command output, or another environment variable.
- Keep the workflow CLI-only. Do not expose it through HTTP, API, MCP, controllers, or request parameters.
- Do not use Telegram MCP or a personal Telegram session for Ladna Founders announcements.
- Never announce automatically after a deployment; explicit user wording is always required.
