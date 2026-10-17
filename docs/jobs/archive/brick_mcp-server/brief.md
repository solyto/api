# Brief: mcp server

type: feature
id: brick
branch: feature/brick_mcp-server
base: experimental
date: 2026-10-17
author: Leander Muskalla

## What

Add an experimental MCP (Model Context Protocol) server to the API so a local AI agent can use everything in Solyto as the authenticated user. It uses a generic proxy: two tools give access to the whole `/api/v1` surface, instead of one hand-written tool per action.

- Install `laravel/mcp` (^1.0). **Installing this package is explicitly approved for this job.**
- An MCP server on an HTTP route (e.g. `/mcp`), authenticated with a Sanctum bearer token.
- Tool `list_endpoints`: returns the `/api/v1` endpoints (method, path, short description), with an optional text filter. Take descriptions from the existing route table and OpenAPI annotations so the list never needs maintaining by hand.
- Tool `call_api`: takes `method`, `path`, optional `query` and `body`. It runs that request against the existing API **as the token's user** and returns the API's JSON response, including validation and error responses, so the agent can correct itself. It must go through the normal routing, middleware, validation and policies; no business logic is duplicated.
- Artisan command (e.g. `php artisan mcp:token {email}`) that creates a non-expiring, clearly named Sanctum token for MCP use and prints it once.
- A config/env flag that turns the MCP route on. It defaults to **off**, so the feature can't go live by accident if this branch is ever deployed.
- Pest tests: auth required, flag off means no route, `list_endpoints` returns real routes, `call_api` acts as the token user and can't reach another user's data, and errors/validation pass through.

## Why

The owner wants to try driving Solyto (todos, calendar, notes, everything) from their local AI agent. A generic proxy gives full access with very little code, and it stays in sync automatically as the API changes.

## Out of scope

- OAuth / Passport, connectors for claude.ai or ChatGPT, or any multi-user or production rollout.
- Hand-written typed tools per module (may come later for frequently used actions).
- MCP resources/prompts, stdio transport, frontend/UI for token management.
- Any change to the DAV server, the `pgsql` connection, or existing API behaviour.
- New migrations (Sanctum's exi` table is enough).

## Notes
                                                                          - Experimental and used only onp it small and self-contained(e.g. `app/Api/Mcp/` or `app/Mcp/`, following how the rest of the codebase- `call_api` should only acceptst not be usable to revoke theMCP token itself or log the user out of everything (e.g. block or warn on `logout`/`logout-all`/token ends how.
- MCP speaks JSON-RPC, so its responses don't use the `ApiResponse` envelope. That's expected
here. Record it as a decision/ed` rather than forcing theenvelope.
- Check the `laravel/mcp` docs `Mcp::web(...)`, test helpers,`mcp:inspector`) before building.
- Document briefly (README sect the flag, create a token andconnect a client, e.g. `claude mcp add --transport http solyto http://localhost:8000/mcp --header "Authorization: Bearer
- Done means `composer test` and `composer analyse` both exit 0.
