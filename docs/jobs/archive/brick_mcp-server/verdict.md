# Verdict: mcp server

id: brick
reviewer: @reviewer
date: 2026-10-17

Branch `feature/brick_mcp-server` was verified. The review surface was `git diff experimental...HEAD`, because the brief sets `base: experimental`.

## Review

TASK-1: PASS
notes: `laravel/mcp` ^1.0 is in composer.json and the lock file at v1.0.2. The brief explicitly approved this package. `routes/ai.php` is published, and the package's provider loads it with no `web` group, so there is no CSRF or session.

TASK-2: PASS
notes: `config/services.php` sets `mcp.enabled` from `(bool) env('MCP_ENABLED', false)`. Neither `phpunit.xml` nor `.env.testing` sets it, so the flag is off by default in tests too. There is no `.env.example` in the repo, so skipping that step was correct.

TASK-3: PASS
notes: `routes/ai.php` registers `Mcp::web('/mcp', SolytoServer::class)->middleware('auth:sanctum')` only when the flag is on. Without a token the endpoint returns a 401 JSON envelope, not a redirect; this is tested.

TASK-4: PASS
notes: `ListEndpointsTool`:
- reads the live router, keeps only `api/v1/*`, and drops HEAD/OPTIONS and the blocked URIs;
- takes each description from the `@OA\<Verb>` `summary`, falling back to `Controller@method`;
- supports a case-insensitive filter.

TASK-5: PASS
notes: The security boundary in `CallApiTool` holds up:
- **Path checks:** `normalisePath` rejects `%`, `\`, `?`, `#`, control characters, `://`, a leading `//`, and empty, `.` or `..` segments.
- **Route check:** the route is matched before dispatch and refused if its URI is outside `api/v1/` or on the block list.
- **Auth:** only the outer `Authorization` header is forwarded, and the user is never taken from the payload. `Auth::forgetGuards()` runs before and after the sub-request, and the `request` binding and facade are restored in a `finally` block.
- **No duplicated logic:** the request goes through `Kernel::handle`, so routing, middleware, FormRequests and policies run unchanged.
- **Block list:** it matches tasks.md and adds `users/change-password`, which deletes every token, so this addition is right. Account deletion (`UserService::destroy`) is only reachable from a console command, not the API.
- **Middleware:** no API middleware relies on `terminate()`, so skipping terminate for the sub-request loses nothing.
- **Responses:** non-JSON responses are summarised instead of returned raw.

TASK-6: PASS
notes: `app:mcp:token {email}` creates a token named `mcp-token` (it does not use the `auth-token-` prefix) with `expiresAt: null`. It prints the token once, logs nothing, and fails on an unknown email. It is registered in `bootstrap/app.php`.

TASK-7: PASS
notes: `McpServerTest` covers four cases over real HTTP: flag off returns 404, no token returns 401, an invalid token returns 401, and `tools/list` returns both tools. The `mcpEnable()`, `mcpPost()` and `mcpTool()` helpers in `tests/Pest.php` are in scope; they are documented in implementation.md. Re-registering the route only affects the current test's application instance.

TASK-8: PASS
notes: All tests use real bearer tokens through the full middleware stack. They cover:
- create a todo, then list it, acting as the token's user;
- another user's todo and note return 403, and the note is not deleted;
- the 422 response includes `errors.title`;
- an unknown path returns 404;
- each blocked endpoint is refused, the token count stays the same, and the token still works afterwards;
- 8 different path-escape attempts are rejected;
- the token command: correct name, no expiry, the printed token works, and an unknown email fails.

TASK-9: PASS
notes: The README section covers the flag, the token command, `claude mcp add`, the inspector, what is blocked, and how to revoke the token. The `docs/STANDARDS.md` change is limited to a single `## Decisions` entry recording the three exceptions.

TASK-10: PASS
notes: I ran both checks myself:
- `composer test` exits 0: 1204 tests, 4186 assertions, no failures.
- `composer analyse` exits 0 with `[OK] No errors`, and `phpstan-baseline.neon` is unchanged.
- Every test, including ones unrelated to this job, shows a `file_get_contents(/workspace/.env)` warning. It comes from the sandbox (`.env.testing` is mounted as `/dev/null` and there is no `.env`), not from this diff.

Scope: nothing outside tasks.md changed. There are no changes to migrations, DAV, `pgsql`, `routes/web.php` or `routes/api.php`. The brief's Notes section is partly garbled. The analyst wrote down the assumptions it made, and the developer followed them; they are reasonable and easy to reverse (block vs. warn only touches `CallApiTool::BLOCKED_URIS`).

## Security

The security points listed in TASK-5 hold up and are covered by tests (TASK-8): cross-user access, the MCP token revoking itself, path escapes, and the user always coming from the token. The token is never logged.

## Overall

APPROVED. There are no blockers.
