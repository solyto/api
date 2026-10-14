# Implementation: Ntfy connection

id: contain
developer: @developer
date: 2026-10-14

## Summary

This is a report-only job. I wrote `docs/reports/ntfy-notifications-2026-10-14.md`, which analyses how to add optional ntfy notifications for operator events (user registration, dev requests). It uses the same layout as the VTODO report: header, Summary, Method, numbered sections, recommendation and open questions.

It describes three delivery options: an operator-level target (A), a per-user channel (B), or both (C). It recommends option A and leaves the decisions to the maintainer as nine open questions.

No application code, config, migrations, routes or lang files were changed.

## Changes

TASK-1: Report §1 "Current state":
- `BaseNotification::via()` channel resolution, the `TelegramChannel`/`TelegramMessage` pattern, and the `user_notification_settings` columns and migrations.
- Finding: notifications are **synchronous** (nothing implements `ShouldQueue`).
- Finding: on-demand (`AnonymousNotifiable`) notifications silently send nothing through `BaseNotification`. The `['database']` fallback is skipped by Laravel (checked against `NotificationSender.php` at the locked v12.58.0), and `resolveLocale()` reads `->settings`.
- There is no event layer.

TASK-2: Report §2, an event inventory with file:line hook points:
- Registration (`AuthService::register`), the `CreateUser` command, the unused `UserService::create`, the `UserObserver` alternative, email verification.
- Dev request created / commented / updated / voted, user deletion, export failure.
- Notes that `created_by_user_id` on dev requests is client-supplied.

TASK-3: Report §3, the ntfy publish API from docs.ntfy.sh/publish and /config: POST to the topic or JSON to the root, the headers, Bearer/Basic token auth, public topics on ntfy.sh, `auth-default-access`, the 4 KB message limit, `X-Cache` and `X-Firebase`. No package needed.

TASK-4: Report §4, options A (with A1/A2 variants), B and C:
- The files each would touch.
- The `BaseNotification` change.
- Migrations: none for A, ⚑ needed for B.
- An SSRF concern for B, because users would choose the URL.
- A comparison table.

TASK-5: Report §5:
- A `services.ntfy` config sketch.
- "Enabled only if url+topic are set", with no ntfy.sh default.
- Per-event toggles.
- Self-hosted instances make no outbound calls.
- The token goes through `DockerSecretHelper`, and the topic may need to be treated as a secret.

TASK-6: Report §6:
- Privacy: which data leaves the instance, minimal content, `X-Cache`/`X-Firebase`, the privacy policy.
- Failure handling: queue only the new notifications, carry scalars, short timeout and `report()`.
- Localisation: lang keys vs English-only.
- Testing: `Http::fake`/`assertNothingSent` for the off-by-default case, `Notification::assertSentOnDemand`.

TASK-7: The Summary, §7 recommendation, phased plan, risks and nine open questions. It states that no code was changed.

## Known issues / follow-ups

- `.env.testing` shows as modified in `git status`, but git can't hash it ("unsupported file type"; it looks like a mount in this sandbox). This job didn't touch it, and it isn't committed.
- Out of scope, noted in the report: `TelegramChannel` ignores failed sends.
- Out of scope, noted in the report: `DevRequest.created_by_user_id` is taken from the request body, not from the authenticated user.
- `docs/backlog/ntfy-notifications.md` is still empty (open question 9).
