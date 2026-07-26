---
name: ladna-trello-card
description: Use only when the user explicitly asks to view, inspect, fix, or implement a specific card on the Ladna Trello board, including requests that provide a Trello card URL, short link, card ID, or unambiguous card title. Do not use for ordinary Ladna development, generic bug discussions, card creation, or board administration.
---

# Ladna Trello Card

## Board Guard

- Use only the `trello_ladna_bugtracker` MCP server.
- Treat `6a65fff3caa682088c980b19` as the only allowed board ID. Its expected name is `Ladna` and its URL code is `6v4AgYbB`.
- Start with `get_active_board_info`. Stop without changing Trello if the active board ID or name does not match.
- Never call `set_active_board`. Pass the allowed board ID to every board-scoped tool.
- Resolve a supplied card URL, short link, or ID with `get_card`, then require its `idBoard` to equal the allowed board ID.
- If only a title is supplied, use `get_lists` and `get_cards_by_list_id` with the allowed board ID. Continue only for one unambiguous match.

## Read-Only Requests

- Treat requests to view, inspect, summarize, or explain a card as read-only.
- Retrieve the card with Markdown enabled. Read comments, checklists, acceptance criteria, and attachment metadata only when relevant to the request.
- Report the card title, current list, description, acceptance criteria, due state, members, labels, and relevant discussion concisely.
- Do not move, update, comment on, label, archive, assign, watch, or otherwise mutate the card.
- Do not download protected Trello attachments directly when MCP metadata or an accessible referenced route is sufficient.

## Implementation Requests

1. Require explicit user wording to fix, implement, or work on the card. Viewing a card does not authorize implementation or Trello mutation.
2. Inspect the complete card and its relevant acceptance criteria before editing source.
3. Retrieve the board lists and require exactly one `In Work` list and one `Done` list. Never create or rename lists.
4. Move the card to `In Work` before source edits. If it is already there, continue. Reopen a card from `Done` only when the user explicitly requested implementation or a fix.
5. Follow all other applicable Ladna domain, testing, versioning, deployment, and repository instructions. Trello authorization does not broaden the requested source, commit, push, or deployment scope.
6. Move the card to `Done` only after the entire requested implementation succeeds and all applicable automated tests, rendered QA, and explicitly requested deployment verification pass.
7. Re-read the card after each transition and verify its list. Leave failed, incomplete, or blocked work in `In Work`.

## Mutation Boundaries

- Limit automatic Trello writes to the required `In Work` and `Done` transitions.
- Add comments, edit content, change labels or dates, manage members or checklists, archive cards, or create cards only when the user explicitly requests that exact action.
- Never mutate another Trello board, even if the shared credentials can access it.

## Final Report

- For read-only work, summarize the verified card and state that Trello was not changed.
- For implementation work, report the card identifier, verified starting and final lists, completed checks, and any reason it remains in `In Work`.
