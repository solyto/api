# Verdict: Ntfy connection

id: contain
reviewer: @reviewer
date: 2026-10-14

## Review

Branch verified: `report/contain_ntfy-connection` (matches brief). Base: `main`
(`.manigot/manigot.json`). `git diff main...HEAD` touches only the job files
under `docs/jobs/contain_ntfy-connection/` and the single deliverable
`docs/reports/ntfy-notifications-2026-10-14.md`. No application code, config,
migrations, routes, lang files or `docs/backlog/ntfy-notifications.md` were
changed, which matches tasks.md "Explicitly not in this job". The `.env.testing`
entry in `git status` is uncommitted and not part of the branch diff
(implementation.md explains it as a sandbox mount artefact).

Code claims were spot-checked against the tree and are accurate:
`BaseNotification::via()` non-`User` → `['database']` at `:38-40`,
`resolveLocale()` reading `->settings` at `:18-22`; `AuthService::register`
at `:15-46` (rollback at `:38-41`, mail at `:43`); `DevRequestService::create`
at `:21-37` sending nothing; `createComment` notification at `:93-98`;
`StoreDevRequestRequest.php:19` client-supplied `created_by_user_id`; the only
`User::create` / `DevRequest::create` call sites (AuthService, CreateUser,
UserService, DevRequestService); `UserService::create` having no caller;
no `ShouldQueue` on notifications; no event/listener layer;
`phpunit.xml:26-27` mailer `array` / queue `sync`.

TASK-1: PASS
notes: §1 covers channel resolution, the Telegram channel/message pattern,
`user_notification_settings` columns + migrations, the non-User fallback, and
queueing (with the useful finding that notifications are synchronous today,
and that on-demand notifications through `BaseNotification` silently send
nothing).

TASK-2: PASS
notes: §2 inventories E1–E10 with file:line hook points, includes the
`CreateUser` command path, the dead `UserService::create`, the `UserObserver`
alternative with its downsides, and states that there is no event layer.

TASK-3: PASS
notes: §3 describes topic/JSON publishing, headers, Bearer/Basic auth,
ntfy.sh public topics vs self-hosted `auth-default-access`, limits; sources
cited in Method, unconfirmed points marked **unverified**. No package needed.

TASK-4: PASS
notes: §4 describes A (A1/A2), B, C with files touched, the `BaseNotification`
change, migrations (none for A, ⚑ for B), no new package, plus an SSRF finding
for B. Stays descriptive; comparison table included.

TASK-5: PASS
notes: §5 gives a placeholder `services.ntfy` sketch, "enabled only if url +
topic set", no ntfy.sh default, per-event toggles, self-hosted = no outbound
calls, token via `DockerSecretHelper`, no real values. Minor imprecision (not a
blocker): with `APP_DEBUG=true` and the variable unset,
`DockerSecretHelper::get()` returns `getenv()`'s `false` coerced to `""`, not
`null`; a later implementation should check the token for emptiness, not
`=== null`.

TASK-6: PASS
notes: §6 covers privacy (data leaving the instance, ntfy.sh/Firebase,
`X-Cache`/`X-Firebase`, privacy policy), failure handling (queue only the new
notifications, scalars, short timeout, `report()` without the token),
localisation (§9.4 lang keys vs English-only decision), and testing with
`Http::fake`/`assertNothingSent`/`assertSentOnDemand` per TESTING.md.

TASK-7: PASS
notes: Summary, recommendation explicitly labelled "not decided", phased plan,
risks, and nine open questions including the three required ones (recipient,
events, off-by-default mechanism). States twice that no code was changed.

## Security

Not run separately. The report contains no secrets, URLs, topics or tokens, and
correctly routes the token through `DockerSecretHelper` and flags the SSRF risk
of option B.

## Overall

APPROVED

No blockers. The deliverable matches the brief and tasks, stays within scope,
and its code references are accurate.
