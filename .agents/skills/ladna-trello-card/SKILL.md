---
name: ladna-trello-card
description: Use only when the user explicitly asks to view, inspect, fix, or implement a specific card on the Ladna Trello board, including requests that provide a Trello card URL, short link, card ID, or unambiguous card title. Do not use for ordinary Ladna development, generic bug discussions, card creation, or board administration.
---

# Ladna Trello Card

## Trello Plugin

- Use the installed `Trello` plugin (`app-6a20b18a639081918c1b438f8381b27e@openai-curated-remote`). Discover its current tools with `tool_search` when available; otherwise inspect the Trello entries in `ALL_TOOLS`.
- Tools currently use the `mcp__codex_apps__trello_` prefix. The table below gives the suffixes and actions; check live schemas before calling them.
- The plugin has no active-board setting. Do not restore the removed standalone MCP server or use its credentials as a fallback. If plugin access fails, report the connection error.

| Purpose | Tool suffix and arguments |
| --- | --- |
| Verify the allowed board | `trelloreadboard(action="get", boardId=<board URL>)` |
| Read a card | `trelloreadcard(action="get", cardIdOrUrl=<URL or returned ARI>)` |
| Find a card by title | `trellosearch(action="search_cards", boardIds=[<board ARI>], query=<title>)` |
| List board lists | `trelloreadlist(action="list_by_board", boardId=<board ARI>)` |
| Enumerate cards on the board | `trelloreadcard(action="list_by_board", boardIdOrUrl=<board ARI>)` |
| Read complete checklists | `trelloreadchecklist(action="list_by_card", cardId=<card ARI>)` |
| Move a card within the board | `trellowritecard(action="move", cardId=<card ARI>, listId=<destination list ARI>)` |

## Board Guard

- Start by reading `https://trello.com/b/6v4AgYbB`. Require `objectId="6a65fff3caa682088c980b19"`, `name="Ladna"`, `shortLink="6v4AgYbB"`, and an open board. Stop without changing Trello if verification fails.
- Retain the returned board `id`: it is an ARI (`ari:cloud:trello::board/workspace/.../...`), distinct from the legacy hexadecimal `objectId`. Scope board calls and searches to that verified ARI.
- Resolve a supplied card URL or ARI with the card `get` action. Convert a bare short link into `https://trello.com/c/<shortLink>`. For a legacy hexadecimal card ID, enumerate cards only on the verified board and match the terminal card-ID segment of the returned ARI before fetching that ARI; include archived cards when resolving that exact identifier.
- Require the fetched card's `boardId` (or nested `board.id`) to equal the verified board ARI. A matching name or shared workspace is insufficient. Missing or conflicting ownership fields block Trello writes and source implementation for that card.
- For a title-only request, search within the verified board and require one unambiguous match after pagination, then fetch and verify the card. Do not choose between duplicate titles. Include archived search results when the user explicitly requests them.
- Use returned `id` ARIs for card, list, and checklist operations. Do not pass URLs, short links, or `objectId` values into write tools or construct ARIs from guessed workspace IDs.
- Follow each collection's actual pagination contract: lists use `pageInfo.hasNextPage/endCursor`; board-card enumeration uses `hasNextPage/nextCursor` and pages lists; checklists and search use `hasMore/nextCursor`. A non-null cursor alone does not mean another page exists. Do not claim uniqueness or completeness from a truncated page.

## Read-Only Requests

- Treat requests to view, inspect, summarize, or explain a card as read-only.
- Read the returned description and relevant acceptance criteria. There is no Markdown-enable parameter. Use the checklist reader when checklist requirements matter instead of assuming embedded card checklists are complete.
- The current plugin advertises no dedicated comment/history or attachment-reading tools. Report discussion and attachment details only when actually available; an empty embedded comments array does not prove the full discussion was read. If missing evidence affects implementation scope, request the relevant details before dependent work; otherwise state the limitation and continue.
- Report the card title, current list, description, acceptance criteria, due state, members, labels, and relevant discussion only to the extent verified. Respect truncation flags such as `labelsHasMore`. For local-date interpretation, use `trelloreadmember(action="get_me")` and its `prefs.timezone`; card due timestamps are UTC.
- Do not move, update, comment on, label, archive, assign, watch, or otherwise mutate the card.
- Use accessible referenced routes or user-provided evidence for protected attachments; do not recover old credentials or bypass attachment authorization.

## Implementation Requests

1. Require explicit user wording to fix, implement, or work on the card. Viewing a card does not authorize implementation or Trello mutation.
2. Inspect the card and all accessible relevant acceptance criteria before editing source; resolve any material evidence gap described above.
3. Retrieve all open board lists and require exactly one `In Work` list and one `Done` list. Keep their returned ARIs. Never create or rename lists.
4. Re-read the card and verify its board before each transition. Use the card `move` action with the destination list ARI, omitting `boardId` for these same-board moves. Move to `In Work` before source edits; if already there, continue. Reopen from `Done` only for an explicit implementation or fix request. If the initial move fails, stop before source edits.
5. Follow all other applicable Ladna domain, testing, versioning, deployment, and repository instructions. Trello authorization does not broaden the requested source, commit, push, or deployment scope.
6. Move the card to `Done` only after the entire requested implementation succeeds and all applicable automated tests, rendered QA, and explicitly requested deployment verification pass. Use `action="move"`; `mark_done` changes completion state and does not implement this list transition.
7. Re-read the card after each transition and verify `listId` (or nested `list.id`) against the destination ARI and confirm its board again. If a move result is uncertain, re-read before retrying. Leave failed, incomplete, or blocked work in `In Work`; if Trello access prevents verification, report the last verified state without claiming the move succeeded.

## Mutation Boundaries

- Limit automatic Trello writes to the required `In Work` and `Done` transitions.
- Add comments, edit content, change labels or dates, manage members or checklists, archive cards, or create cards only when the user explicitly requests that exact action.
- Explicit authorization does not create a missing plugin capability: report unsupported actions rather than inventing tool names or falling back to the removed server.
- Never mutate another Trello board, even if the shared credentials can access it.

## Final Report

- For read-only work, summarize the verified card and state that Trello was not changed.
- For implementation work, report the card identifier, verified starting and final lists, completed checks, and any reason it remains in `In Work`.
