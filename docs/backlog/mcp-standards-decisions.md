# Backlog: record the MCP server's standards exceptions

created: 2026-11-21

## What

The MCP server (`app/Mcp/`, added after 1.1.4, experimental, off by default
via `MCP_ENABLED`, admins only through `AuthenticateMcpToken`) deviates from
STANDARDS in ways that look intended but aren't recorded:

- §12.1: `app/Mcp/` is a top-level folder §12.1 doesn't list.
- §1.4 / §12.2: a second route file, `routes/ai.php` (laravel/mcp's
  convention), and its own token auth (`mcp_tokens`) instead of Sanctum.
- §12.8: the 11 tools call module services directly. The owner decided
  (2026-11-21) that entry points call services directly, like controllers;
  task 1 of `dissolve-integration-gateway.md` writes that into §12.8.
- `CreateTodo`, `UpdateTodo` and `CreateDevRequest` copy their FormRequest
  rules by hand. The rules differ on purpose (strict `Y-m-d` dates, existing
  tag/category ids instead of parsing the title), so they can't simply share
  one source.

## Suggestion

Add one Decision to STANDARDS covering `app/Mcp`, `routes/ai.php` and the MCP
token auth, with a note that the rules in the three tools differ from the REST
FormRequests on purpose. Do it together with task 1 of
`dissolve-integration-gateway.md`.
