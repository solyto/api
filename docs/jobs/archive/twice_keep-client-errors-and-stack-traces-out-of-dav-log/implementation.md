## Summary

The `dav` log channel now uses its own level (`DAV_LOG_LEVEL`, default `warning`) and rotates daily, keeping logs for `LOG_DAILY_DAYS` (default 14) days. Sabre's INFO-level 4xx stack traces from scanner traffic no longer reach the log. Warnings and errors, including Sabre's 501s, are still logged.

## Changes

TASK-1: `config/logging.php` — the `dav` channel now uses `driver: daily`, `level: env('DAV_LOG_LEVEL', 'warning')` and `days: env('LOG_DAILY_DAYS', 14)`. `path` and `replace_placeholders` are unchanged. `composer test` and `composer analyse` both exit 0.

## Known issues / follow-ups

- With the `daily` driver, the files are named `storage/logs/dav-YYYY-MM-DD.log` instead of `dav.log`. Any external tail, log shipping or deployment-repo tooling that reads `dav.log` by path needs updating. This can't be checked from this repo.
- Brief step 2 (blocking `/.git`, `.env`, `wp-*` and `*.tfvars` at the reverse proxy) belongs in the solyto/deployment repo.
- At `warning`, 401s from misconfigured real clients are no longer logged either. Set `DAV_LOG_LEVEL=info` temporarily when debugging a sync problem.
- The worktree had no `vendor/` and no `storage/framework/*` dirs. I ran `composer install` from the lock file and created the gitignored storage dirs. The first `composer test` run after that had 2 failures. Two reruns passed cleanly, so it looked like a one-off flake caused by the fresh setup.
