---
name: tell-slastya-after-deploy
description: Use only when the user explicitly asks to tell, say, notify, or write Slastya about the latest Ladna changes as part of a deploy, release, publish, or production update. Do not trigger for ordinary deploys, ordinary summaries, or messages to other people.
---

# Tell Slastya After Deploy

## Workflow

Use this skill only after a Ladna deploy is requested and the user explicitly asks to message Slastya.

1. Finish the requested implementation, verification, commit, push, and production deploy first.
2. Collect only confirmed facts from the deployed change: user-facing features, important behavior changes, and any short instructions Slastya needs to follow.
3. Use the Telegram connector to find the intended Slastya contact or chat. If more than one plausible Slastya appears and the correct chat cannot be inferred, ask the user before sending.
4. Write the message in Ukrainian.
5. Start the message with the fixed intro: `Хоботун за дорученням свого повелителя Віктора Найвеличнішого повідомляє:`
6. Keep the message practical: what changed, where to click, what to be careful with, and what is already deployed.
7. Do not include internal implementation details, commit hashes, tests, migration names, secrets, or deploy logs unless the user explicitly asks.
8. Send the message only after the production deploy succeeds. If deploy is blocked or only local verification happened, do not send; report the blocker to the user.

## Message Shape

Prefer this structure:

```text
Хоботун за дорученням свого повелителя Віктора Найвеличнішого повідомляє:

У Ladna вже оновлено ...

Що робити:
- ...
- ...

Важливо:
- ...
```

Keep bullets short and operational. Mention that the change is already available only when the deploy actually completed.
