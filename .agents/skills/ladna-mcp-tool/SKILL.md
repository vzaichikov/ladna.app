---
name: ladna-mcp-tool
description: Use when adding or changing Ladna MCP tools. Keeps MCP tools tenant-scoped, ability-gated, audited, and covered by feature tests.
---

# Ladna MCP Tool

## Rules

- Register tools on `App\Mcp\Servers\LadnaStudioServer`.
- Put tools in `App\Mcp\Tools`.
- Never accept `account_id`, `studio_id`, `tenant_id`, `user_id`, or `trainer_id` as a tool argument for account scoping.
- Resolve account scope only through `App\Support\Mcp\McpAccountContext`. It accepts either the legacy account API token authenticated by `AuthenticateAccountApiToken` or an account-bound Passport client authenticated by `ResolveMcpOAuthConnection`.
- Gate every tool with an explicit `AccountApiTokenAbility`. OAuth tools must also have an explicit, fail-closed mapping in `McpOAuthToolAccessPolicy` to current `StudioPermission` values.
- Keep `/mcp/ladna-studio` compatible for account service keys. User connections use `/mcp/ladna-studio/{accountSlug}` and must remain read-only unless the product scope explicitly changes.
- Never trust an OAuth `resource` value as tenant authority. The OAuth client must be permanently bound to one `oauth_clients.account_id`, and middleware must compare that account to the route on every call.
- Record every tool call in `mcp_tool_invocations`, including failed/denied calls when possible.
- Return `Response::structured()` for machine-readable data.
- Use account timezone for calendar inputs and outputs.
- Add or update focused PHPUnit feature tests for both credential types, live permission denial, account scoping, tool-list filtering, audit attribution, and the happy path.

## Current Abilities

- `website_leads:create`: existing website lead API.
- `mcp:read`: read-only MCP tools such as studio profile and class counts.
- `mcp:bookings:create`: reserved for booking creation tools.
- `mcp:bookings:cancel`: reserved for booking cancellation tools.
- `mcp:customers:read`: reserved for customer lookup tools.
