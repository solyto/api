# Implementation: email functionality

id: initiative
developer: @developer
date: 2026-11-13

## Summary

Report job. The only deliverable is `docs/reports/email-client-2026-11-13.md`: the current state, protocols and providers, PHP library candidates, five architecture options (A proxy, B sync/cache, C JMAP/provider APIs, D hosted mailboxes, E inbound mail → todo/note), security/privacy, integrations, a comparison table, a recommendation (labelled as one, not a decision), follow-up jobs and ten open questions. No application code, config, migrations, routes, lang files or `composer.json` were changed. No package was added.

## Changes

TASK-1: current state (system-only Mailgun mail, `DockerSecretHelper`, the `UserPlugin` `encrypted:array` pattern and its APP_KEY-rotation handling, no outbound-connection guard, the modules an email feature would touch) → report §1. Read-only.
TASK-2: protocol and provider research from primary sources fetched on 2026-11-13 (RFC 9051, Google's less-secure-apps shutdown and restricted-scope assessment, Outlook.com and Exchange Online basic-auth retirement, iCloud, Fastmail) → report §2. Unconfirmed claims are marked unverified.
TASK-3: library research from Packagist and GitHub metadata (`ext-imap` moved to PECL in 8.4, `directorytree/imapengine` vs `webklex/php-imap`, `zbateson/mail-mime-parser`, `symfony/html-sanitizer` vs HTMLPurifier, Symfony Mailer XOAUTH2 with no new package) → report §3. All are presented as candidates that need approval.
TASK-4: options A–E with data-model sketches (all MariaDB, migrations flagged), endpoints, load (IDLE vs PHP-FPM), effort → report §4.
TASK-5: credentials, SSRF/port scanning, HTML/XSS/tracking, attachments and send abuse, logging hygiene (Telescope `MailWatcher` stores raw messages), GDPR export and deletion → report §5.
TASK-6: contacts autocomplete (⚑ pgsql reads), mail → todo/note/event, the notification-settings migration, localisation, frontend contract → report §6.
TASK-7: wrote `docs/reports/email-client-2026-11-13.md` (comparison, recommendation, follow-ups, open questions). TASK-1–6 changed no files, so their results are committed with this report.
TASK-8: this file. `composer test` exits 0 (1415 tests pass) and `composer analyse` exits 0 ("No errors").

Environment notes, no repo change:

- `vendor/` was missing, so I ran `composer install` from the existing lock (the lock didn't change).
- The gitignored `storage/framework/*` and `storage/app/{public,private}` directories were missing in this worktree. I created them. The first test run failed `ExportTest` "FinanceWealthExportService writes one row per field value" because `storage/app/public/user` didn't exist yet.

## Known issues / follow-ups

- **Flaky test (pre-existing, not touched):** `app/Api/Users/Tests/PasskeyTest.php:220-227` builds the COSE key from `openssl_pkey_get_details()` `x`/`y`, which aren't zero-padded to 32 bytes. In about 1 in 128 runs a coordinate is 31 bytes, and `PasskeyService::verifySignature` (`:265`) throws "Failed to load EC public key." It failed once in the full suite and passed on reruns. Fix: `str_pad($x, 32, "\0", STR_PAD_LEFT)` in the test.
- Side finding in the report: the `failover` mailer references an undefined `smtp` mailer (`config/mail.php:74-80`).
- Side finding in the report: export ZIPs are written to the public-visibility `user_data` disk (`ExportService.php:69-71`). Whether that path is web-served depends on the deployment.
- `.env.testing` shows as modified in `git status` but git can't hash it ("unsupported file type", a mount). It was left alone.
