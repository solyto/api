# Tasks: Ntfy connection

id: contain
analyst: @analyst
date: 2026-10-14

## Context

Job type is **report**. The deliverable is one markdown file in `docs/reports/`
(suggested name: `docs/reports/ntfy-notifications-2026-10-14.md`, same layout as
`docs/reports/vtodo-caldav-sync-2026-10-14.md`: header with date/branch/scope,
Summary, Method, numbered sections, Open questions). **No application code,
config, migrations, routes or lang files change in this job.**

Branch check: worktree HEAD is `report/contain_ntfy-connection`, matching
`brief.md`.

What I found while reading (starting points for the developer, not conclusions):

- `app/Shared/Notifications/BaseNotification.php` — `via()` picks channels from
  per-user `UserNotificationSettings` columns `{type}_{ui,push,email,telegram}`.
  For a non-`User` notifiable it returns `['database']` (`:38-40`), so an
  on-demand / `AnonymousNotifiable` route would currently get the wrong channel.
- `app/Shared/Notifications/Channels/TelegramChannel.php` + `TelegramMessage.php`
  — the only custom channel; it's the pattern to copy for an `NtfyChannel` /
  `NtfyMessage` (`toNtfy()`).
- Registration: `app/Api/Users/Services/AuthService.php::register` (`:15`),
  called from `AuthController::register`; there is a second user-creation path
  in `app/Api/Users/Commands/CreateUser.php`. No events/listeners exist in `app/`
  today.
- Dev requests: `app/Api/DevRequests/Services/DevRequestService.php::create`
  (`:21`) sends no notification today; `createComment` (`:83`) already sends
  `DevRequestCommentNotification` to the request author.
- Admins: `User::isAdmin()` (`role` admin/super_admin), `HandlesApiAuth::isAdmin`.
- Config/secrets: `config/services.php` + `DockerSecretHelper` (an ntfy access
  token would be a secret); `config/telegram.php` has an operator-level
  `debug_chat_id` env, which is a precedent for operator-only targets.
- `docs/backlog/ntfy-notifications.md` exists but is empty.
- `docs/STANDARDS.md` §9.4: user-facing messages are Notifications extending
  `BaseNotification`, copy from `resources/lang/*`.

## Unclear scope (the report should raise these, not decide them)

1. **Who receives the ntfy messages?** The brief's examples (user registration,
   new dev requests) are *operator/admin* events, which points to one
   instance-level ntfy target set by the operator. "Re-use our notification
   channels" could also mean a per-user ntfy channel next to push/email/telegram
   in `UserNotificationSettings`. The report should describe both and recommend
   one; it should not assume.
2. **Which events?** Only "e.g. user registration, dev requests" is given. The
   report should list candidate events with their hook points and let the
   maintainer choose.
3. **"Not auto-on"**: the off-by-default mechanism (unset config = off, an
   explicit env flag, or per-event toggles) is an open choice.

## Task breakdown

TASK-1: Document the current notification architecture (BaseNotification channel resolution, the TelegramChannel/TelegramMessage pattern, UserNotificationSettings columns, the non-User `['database']` fallback, and queueing) as the "Current state" section of the report.
  files: read-only — app/Shared/Notifications/BaseNotification.php, app/Shared/Notifications/Channels/*, app/Api/Users/Models/UserNotificationSettings.php (+ its migration), app/Api/Notifications/*, config/webpush.php, config/telegram.php; write docs/reports/ntfy-notifications-2026-10-14.md
  depends: none
  risk: low — reading and describing existing code only.

TASK-2: Inventory candidate operator events (user registration incl. the CreateUser command path, email verification, dev request created/commented/status changed, any others found) with exact file:line hook points, and note that no event/listener layer exists today.
  files: read-only — app/Api/Users/Services/AuthService.php, app/Api/Users/Controllers/AuthController.php, app/Api/Users/Commands/CreateUser.php, app/Api/DevRequests/Services/DevRequestService.php, app/Api/DevRequests/Controllers/DevRequestController.php; write the report
  depends: none
  risk: low — inventory only; the risk is missing a creation path, so search for every `User::create` / `DevRequest::create`.

TASK-3: Research the ntfy publish API (HTTP POST/JSON to `{server}/{topic}`, headers for title/priority/tags/click, auth via access token or basic auth, self-hosted vs ntfy.sh) and cite primary sources (docs.ntfy.sh).
  files: write the report (Method + an "ntfy API" section)
  depends: none
  risk: low — external docs only; mark anything not confirmed from docs.ntfy.sh as unverified.

TASK-4: Describe the design options for delivery: (A) instance/operator-level target via on-demand notification (`Notification::route('ntfy', …)`) with a new `NtfyChannel`/`NtfyMessage` modelled on TelegramChannel; (B) per-user ntfy channel added to BaseNotification/UserNotificationSettings; (C) both. For each, list the files a later implementation would touch, the needed BaseNotification change for non-User notifiables, whether a migration is needed (B needs one), and whether a new package is needed (prefer Laravel's HTTP client, no new package).
  files: write the report
  depends: TASK-1, TASK-2, TASK-3
  risk: medium — this is where scope can creep; must stay a description of options and must flag that option B means a migration (hard rule: show migrations first).

TASK-5: Describe the opt-in / off-by-default mechanism and configuration: config keys (e.g. a `services.ntfy` block with url/topic from env, token via `DockerSecretHelper`), "disabled unless url+topic set" semantics, per-event toggles, and behaviour for self-hosted instances with no config (no outbound calls at all).
  files: write the report (reference config/services.php, app/Shared/Helpers/DockerSecretHelper.php)
  depends: TASK-4
  risk: medium — touches secret handling; the report must not include real values and must state that the token goes through DockerSecretHelper, not `.env`.

TASK-6: Cover cross-cutting concerns: privacy (what user data, e.g. email addresses or dev-request text, would leave the instance to a third-party ntfy server like ntfy.sh), failure handling (queued, ntfy outage must not break registration), localisation (STANDARDS §9.4 lang keys vs operator-only English), and a testing approach (`Http::fake`, `Notification::fake`) per docs/TESTING.md.
  files: write the report (reference docs/STANDARDS.md §9.4, docs/TESTING.md)
  depends: TASK-4, TASK-5
  risk: medium — privacy is the main concern for a "private" app and needs to be explicit.

TASK-7: Write the Summary, recommendation, phased follow-up plan and the open questions for the maintainer (at least the three listed under "Unclear scope" above), and confirm the report states that no code was changed.
  files: docs/reports/ntfy-notifications-2026-10-14.md
  depends: TASK-1 … TASK-6
  risk: low — editorial; the risk is presenting a recommendation as a decision.

## Explicitly not in this job

- Implementing NtfyChannel, config, migrations or event hooks.
- Filling `docs/backlog/ntfy-notifications.md`. It is a separate file and the brief doesn't ask for it; leave it unless the maintainer says otherwise.
