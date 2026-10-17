# Implementation: mcp server

id: brick
developer: @developer
date: 2026-10-17

## Summary

An experimental MCP server on `POST /mcp` (laravel/mcp v1.0.2). It is protected by `auth:sanctum` and only registered when `MCP_ENABLED=true`, which is off by default. It has two tools:

- `list_endpoints` lists the `/api/v1` routes. Each description comes from the controller action's `@OA` summary.
- `call_api` sends a request through the normal HTTP kernel, forwarding the MCP connection's bearer token. That means routing, middleware, FormRequests and `HandlesApiAuth` all run unchanged, and the API's JSON response (including 4xx/422 envelopes) is passed back to the agent.

`app:mcp:token {email}` prints a non-expiring `mcp-token` once. `composer test` (1204 passed, 0 failed) and `composer analyse` (no errors, baseline unchanged) are both green.

I went with the analyst's assumptions from `tasks.md`, since nobody could confirm them:
- module `app/Api/Mcp/`
- route file `routes/ai.php`
- command `app:mcp:token`
- flag `services.mcp.enabled`
- a hard block on the auth endpoints

## Changes

TASK-1: `composer require laravel/mcp:^1.0` (composer.json/lock). Published `routes/ai.php`. The package loads `routes/ai.php` itself with no middleware group, so there is no CSRF or session.
TASK-2: `config/services.php` gets `mcp.enabled` ← `env('MCP_ENABLED', false)`. There is no `.env.example` in the repo, so I skipped that part.
TASK-3: `app/Api/Mcp/Servers/SolytoServer.php` (name, version, instructions, both tools). `routes/ai.php` registers `Mcp::web('/mcp', …)->middleware('auth:sanctum')` only when the flag is on.
TASK-4: `app/Api/Mcp/Tools/ListEndpointsTool.php`:
- reads the router's `api/v1/*` routes, drops HEAD/OPTIONS and the blocked URIs;
- takes the description from the docblock's `@OA\<Verb>(… summary="…")`, falling back to `Controller@method`;
- has an optional case-insensitive `filter`.
TASK-5: `app/Api/Mcp/Tools/CallApiTool.php`:
- Validates `method` (GET/POST/PUT/PATCH/DELETE, case-insensitive), `path`, `query` and `body`.
- Path rules: no control characters/space, `\`, `?`, `#` or `%`, no `://`, no leading `//`, no empty, `.` or `..` segments. A leading `/api/v1/` is optional.
- Matches the route before dispatching. It refuses anything whose matched URI is outside `api/v1/` or on the block list. The block list is the public `auth/*` routes, `auth/logout`, `logout-all`, `refresh`, `revoke-token`, plus `users/change-password` (it deletes all tokens).
- Forwards the outer `Authorization` header and calls `Kernel::handle()`. Before and after, it calls `Auth::forgetGuards()` so the sub-request authenticates from its own header. Afterwards it restores the `request` binding.
- Returns `{status, body}`. 4xx/5xx results are marked as MCP tool errors; responses that aren't JSON are summarised instead of returned raw.
TASK-6: `app/Api/Mcp/Commands/CreateMcpToken.php` (`app:mcp:token {email}`): `createToken('mcp-token', expiresAt: null)`, prints the token once, and fails for an unknown email. Registered in `bootstrap/app.php`.
TASK-7: `app/Api/Mcp/Tests/McpServerTest.php`: flag off gives 404, no token or an invalid token gives 401, and `tools/list` returns both tools. Added the `mcpEnable()`, `mcpPost()` and `mcpTool()` helpers to `tests/Pest.php`. The calls go over real HTTP with a real bearer token.
TASK-8: `ListEndpointsToolTest.php`, `CallApiToolTest.php` and `CreateMcpTokenTest.php` cover:
- real routes and OA summaries, the filter, and that blocked routes are left out;
- create-then-list as the token user, and query pass-through;
- another user's todo/note gives 403 (and the note is not deleted);
- the 422 and 404 envelopes;
- every blocked endpoint is refused and the token still works afterwards;
- 8 path-escape variants are rejected;
- the command: name, no expiry, the printed token works, unknown email fails.
TASK-9: README section (flag, token, `claude mcp add`, inspector, what is blocked, how to revoke). `docs/STANDARDS.md` `## Decisions` gets one entry with three exceptions: the JSON-RPC envelope, `routes/ai.php`, and the HTTP-kernel proxy instead of `app/Shared`.
TASK-10: full `composer test` and `composer analyse` both exit 0. `phpstan-baseline.neon` is unchanged.

## Known issues / follow-ups

- The analyst's assumptions (listed above) were never confirmed by the owner. If "warn" was meant instead of "block" for the auth endpoints, only `CallApiTool::BLOCKED_URIS` / `isBlocked()` changes.
- `call_api` forwards only `Authorization`, `Accept` and `Content-Type`. It does not send `X-Client-Version`, so the MCP token's `client_version` stays empty. Multipart uploads (profile image, covers, imports) can't go through the JSON-only proxy.
- Endpoint descriptions come from a regex over docblocks. Actions without an `@OA` operation (e.g. `forgotPassword`) show `Controller@method`; all of those are blocked auth routes anyway.
- There is no rate limit on `/mcp`, matching the rest of the authenticated API. `TrackApiTraffic` still counts every proxied call.
- Locally, `storage/framework/*` was missing from the worktree, so `artisan`/composer scripts failed. I created the directories (they are gitignored, not committed).
- `.env.testing` shows as modified in `git status`, but it's a character-device mount, not a real file, so I couldn't commit it and left it untouched.
