# Verdict: Analyze Bot Traffic

id: sheet
reviewer: @reviewer
date: 2026-10-14

## Review

Branch checked: `feature/sheet_analyze-bot-traffic`. Base: `main` (`.manigot/manigot.json`).
`git diff main...HEAD` touches only `docs/`: the job files and the new
`docs/reports/bot-traffic-detection-2026-10-14.md`. No application code, config,
migrations, routes or lang files changed, which matches the scope in tasks.md.

I checked these citations against the code, and all of them are accurate:
- the limiters in `AppServiceProvider.php:44-58`, including the `auth-login` key `email ?: ip`
- the throttles on `routes/api.php:70-76`, and that no `throttle` exists anywhere else in `routes/`
- `TRUSTED_PROXIES` in `bootstrap/app.php:27-29` and the 429 mapping
- the DAV backend (no throttle, no `email_verified_at` check)
- `LoginRequest` and the `auth.policy.confirmation` default
- `SESSION_DRIVER` defaulting to redis
- no `viewPulse` gate exists
- the hourly `SyncFeeds` schedule
- the `og:image` fetch per new item (`FeedReader.php:85-114`, `FeedService::syncFeed`)
- the export 24-hour check
- the unvalidated `Http::get` in `GoodreadsService`, `LibraryCoverService` and `UrlCrawlerService`

TASK-1: PASS
notes: Report §1 lists the limiters, verification policy, trusted proxies, Pulse recorders, token, session, AI and feed metadata, logging and DAV auth, with file:line references. It keeps "configured" separate from "unverified in production".

TASK-2: PASS
notes: Report §2 lists 14 endpoints with their cost drivers and limits, and covers every fetcher named in tasks.md, including quick-add reachability. It correctly leaves the operator-only `app:feeds:import` out of the vectors. The SSRF side finding is clearly marked as out of scope and becomes a follow-up job.

TASK-3: PASS
notes: Report §3–§4 gives signals for threats (a), (b), (c) and credential stuffing. For each signal it states the data needed, whether that data exists today (✅/◐/❌) and the false-positive risk, including the frontend's polling. It cites OWASP OAT and the Laravel docs with the 2026-10-14 fetch date.

TASK-4: PASS
notes: Report §5–§7 sets out options A–D with effort, risk, privacy impact and the self-hosted default, plus a comparison table. The recommendation (A then B) is not presented as a decision. The DAV-touching items (C2, the `/dav` throttle, DAV import SSRF) and the C5 migration are flagged ⚑.

TASK-5: PASS
notes: The report is at the suggested path in the ntfy-report format (header with `read: false`, Summary, Method, numbered sections, follow-up jobs, 11 open questions including the four from tasks.md). I found no secrets or `.env` values in it.

TASK-6: N/A (optional, skipped)
notes: implementation.md gives a reasonable reason for skipping it. `docs/backlog/analyze-bot-traffic.md` is unchanged.

Verification (run by the reviewer in this worktree):
- `composer test`: exit 0, 2167 assertions. The 758 warnings come from the `.env` file missing in the sandbox and are not caused by this job.
- `composer analyse`: exit 0, "No errors".

Not a blocker, for information: the `.env.testing` modification in `git status` is the character-device mount described in implementation.md. It is not committed.

## Security

The developer's analysis flagged an SSRF surface: user-supplied URLs are fetched with no private or loopback address check. This job doesn't fix it, and correctly proposes it as follow-up job §7.6.

## Overall

APPROVED

Nothing blocks the merge.
