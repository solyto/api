# Brief: ntfy notifications for instance admins

type: feature
id: resident
branch: feature/resident_ntfy-notifications-for-instance-admins
date: 2026-10-14
author: Leander Muskalla

## What
Send two operator events to one instance-level ntfy server and topic: "New user registered" and "New dev request: <title>". Add `NtfyChannel` and `NtfyMessage` in `app/Shared/Notifications/Channels/`, modelled on `TelegramChannel`/`TelegramMessage` (JSON `POST` to the server root through Laravel's HTTP client, bearer token only when one is configured). Add a new `AdminNotification` base class next to `BaseNotification` (which stays untouched), with a `via()` that returns `[]` unless ntfy is configured. Two notifications extend it, each `ShouldQueue` with explicit `$tries`/`$timeout`, carrying scalars only. Hooks are one direct on-demand call each: end of `AuthService::register` (after the verification mail) and `DevRequestService::create` (after the screenshot is saved).

Config in `config/services.php`: `NTFY_URL` from `env()`, `NTFY_TOPIC` and `NTFY_TOKEN` through `DockerSecretHelper`. No defaults and no fallback to ntfy.sh. Enabled only when URL and topic are both set; unset means no message built, nothing queued, no outbound call.

## Why
The instance operator wants to hear about sign-ups and dev requests. Off by default matters for self-hosted instances: they must make no outbound call unless the operator opted in.

## Decisions
- Option A (operator target) only; no per-user ntfy channel, so no migration, API or frontend change and no SSRF surface.
- Anonymous content: no email, name, description or screenshot. The dev request title is sent in full (free text, user-written).
- English-only copy, since the reader is the instance admin. Needs a STANDARDS decision entry (operator alerts are not user-facing under §9.4, like the English-only API envelope messages in §12.10).
- Registration is announced right after registration (E1), not on email verification.
- No `X-Cache: no` / `X-Firebase: no` headers, since content is anonymous.
- Only the new notifications are queued; the existing nine stay synchronous.
- No per-event toggles, no `NTFY_ENABLED` flag.

## Out of scope
Per-user ntfy channel (option B), other events (comments, votes, CLI actions, operational alerts such as failed exports), Laravel events/listeners, `BaseNotification` changes, the silent failure in `TelegramChannel`.

## Notes
Tests per docs/TESTING.md with `Http::fake()`: off means `Http::assertNothingSent()` on register and dev request create; on sends a root-URL JSON `POST` with topic, title and message only, and `Authorization: Bearer` only when a token is set; a 500 response and a `ConnectionException` don't break the request. `NtfyChannel` must log status and host on failure, never the token. Source: `docs/reports/ntfy-notifications-2026-10-14.md`.

Env contract (what a container sees): `NTFY_URL` as a plain env var; `NTFY_TOPIC_FILE` and `NTFY_TOKEN_FILE` (paths to Docker secrets) in production; plain `NTFY_TOPIC` / `NTFY_TOKEN` only when `APP_DEBUG=true` (`DockerSecretHelper`). `selfhosted/setup.sh` creates unused optional secrets as empty files, so `DockerSecretHelper::get()` returns `""`, not `null`: the enabled check must use `filled()` and an empty token means no `Authorization` header. Test the empty-file case. Add the three names to `.env.example`, commented out.

### Related changes in other repos (not done from this repo)
Both need the secrets on the `php`/`api` and `queue` services only (`php` evaluates `via()`, `queue` sends); `dav`, `nginx` and `imgproxy` need nothing. Checked via GitHub on 2026-10-14; the deployment secret-file mechanism (playbooks, `group_vars`) was not inspected.
- `github.com/solyto/selfhosted`:
  - `compose.yml`: top-level secrets `ntfy_topic`, `ntfy_token`; on `api` and `queue` add `NTFY_TOPIC_FILE=/run/secrets/ntfy_topic`, `NTFY_TOKEN_FILE=/run/secrets/ntfy_token` and the service-level `secrets:` entries.
  - `.env`: `NTFY_URL=` (empty), in its own block; make sure it reaches the containers.
  - `setup.sh`: add `ntfy_topic`, `ntfy_token` to the optional `write_empty` list, or compose fails on a missing secret file.
  - `README.md`: list both under optional secrets, note ntfy is off unless URL and topic are set.
- `github.com/solyto/deployment`:
  - `templates/api/compose.yml.j2`: same secrets, env vars and service entries for `php` and `queue`.
  - `templates/api/.env.j2`: `NTFY_URL={{ ntfy_url }}`, with an empty default in `group_vars` so existing hosts keep working.
  - Provision the `ntfy_topic` and `ntfy_token` secret files the same way as the existing ones.
