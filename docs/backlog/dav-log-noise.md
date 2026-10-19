# Backlog: keep client errors and stack traces out of dav.log

created: 2026-10-18

## What

The `dav` channel (`config/logging.php:96-101`) logs at `LOG_LEVEL`
(default `debug`) with the `single` driver. SabreDAV logs every 4xx it throws
at INFO with a full stack trace, so `dav.log` is mostly scanner traffic
(`.env`, `%2eenv`, `wp-config.php`, `terraform.tfvars`, `backup/.git/config`
→ 404, unauthenticated GETs on `/` → 401) and it grows without limit.

Raise the channel's level to `warning` and rotate it (`daily` with `days`).

## Why

On 2026-10-18 the scanner traces filled the tail of the log. Real errors
(rule 4.9: logs are for failures) are hard to find among them. The probes
themselves are harmless: they resolve against Sabre's virtual tree, never
the filesystem.

## Steps

1. In `config/logging.php`, give `dav` its own level (e.g.
   `env('DAV_LOG_LEVEL', 'warning')`) and switch to `daily` with a retention
   in days.
2. Optionally (deployment repo): reject `/.git`, `.env`, `wp-*` and `*.tfvars`
   paths at the reverse proxy for the DAV host.

## Notes

- Trade-off: at `warning`, 401s from misconfigured real clients disappear too.
  Lower `DAV_LOG_LEVEL` temporarily when debugging a sync problem.
- 501s (Sabre logs them at ERROR) stay visible; see [[dav-unhandled-post]].
- Check: `grep -n "'dav'" -A4 config/logging.php` shows a non-debug level
  and the `daily` driver.
