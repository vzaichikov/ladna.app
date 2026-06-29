---
name: ladna-mcp-tool
description: Use when adding or changing Ladna MCP tools. Keeps MCP tools tenant-scoped, ability-gated, audited, and covered by feature tests.
---

# Ladna MCP Tool

## Rules

- Register tools on `App\Mcp\Servers\LadnaStudioServer`.
- Put tools in `App\Mcp\Tools`.
- Never accept `account_id`, `studio_id`, `tenant_id`, `user_id`, or `trainer_id` as a tool argument for account scoping.
- Resolve account scope only through `App\Support\Mcp\McpAccountContext`, which reads the bearer API token authenticated by `AuthenticateAccountApiToken`.
- Gate every tool with an explicit `AccountApiTokenAbility`.
- Record every tool call in `mcp_tool_invocations`, including failed/denied calls when possible.
- Return `Response::structured()` for machine-readable data.
- Use account timezone for calendar inputs and outputs.
- Add or update focused PHPUnit feature tests for auth, ability denial, account scoping, and the happy path.

## Current Abilities

- `website_leads:create`: existing website lead API.
- `mcp:read`: read-only MCP tools such as studio profile and class counts.
- `mcp:bookings:create`: reserved for booking creation tools.
- `mcp:bookings:cancel`: reserved for booking cancellation tools.
- `mcp:customers:read`: reserved for customer lookup tools.
