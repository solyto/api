# Verdict: email functionality

id: initiative
reviewer: @reviewer
date: 2026-11-13

## Review

Branch `report/initiative_email-functionality` is checked out and matches `brief.md`. `git diff main...HEAD` touches only `docs/jobs/initiative_email-functionality/*` and the new `docs/reports/email-client-2026-11-13.md`. No application code, config, migrations, routes, lang files or `composer.json`/`composer.lock` changed. Nothing changed that `implementation.md` doesn't mention.

TASK-1: PASS
notes: §1 covers Mailgun-only system mail, `DockerSecretHelper`, the `UserPlugin` `encrypted:array` precedent incl. `DecryptException` handling, the missing outbound guard, and the touched modules. I spot-checked these refs and they're accurate: `config/mail.php:17`, `:74-80`, `UserPlugin.php:33`, `UserPluginService.php:143-152`, `CalendarImport.php:224-236`, `UrlCrawlerService.php:41-43`, `UserPluginController.php:107`, `ExportService.php:69-71`, `config/filesystems.php:41-47`, `AppCalendarsPDO.php:431`, `routes/web.php:7`, `config/telescope.php:178`, `vendor/.../MailWatcher.php:47-48`. A grep confirms no private-IP check exists in `app/`.

TASK-2: PASS
notes: §2 cites RFC 9051/7162/6154/6409/8314/8620/8621 and official Google, Microsoft, Apple and Fastmail pages, all date-stamped 2026-11-13. Claims that weren't confirmed from a primary source are marked **unverified**, as asked.

TASK-3: PASS
notes: §3 covers `ext-imap` moving to PECL (php.net + RFC quotes), compares ImapEngine and webklex, covers MIME parsing, per-user SMTP via the installed Symfony Mailer (no new package), and symfony/html-sanitizer vs HTMLPurifier including licences. Every package is presented as a candidate needing approval. `composer.json` is untouched.

TASK-4: PASS
notes: §4 describes options A–E, each with a data model, endpoints, load (IDLE vs PHP-FPM is addressed) and effort, and ends in a comparison table. Migrations are flagged as hard-rule items, MariaDB only. pgsql/DAV involvement is flagged with ⚑ in §1.4/§6.1. It stays a comparison and doesn't decide anything.

TASK-5: PASS
notes: §5 covers credentials and APP_KEY rotation, the SSRF and port-scan guard, HTML/XSS and tracking (including the imgproxy caveat), attachments and send abuse, logging hygiene (Telescope `MailWatcher` and `hideRequestParameters`), GDPR export and deletion, and the privacy positioning. Every finding is concrete, and no secret values appear.

TASK-6: PASS
notes: §6 covers contacts autocomplete (⚑ pgsql reads), mail → todo/note/`.ics`, QuickAdd, the `mail_received` notification columns (⚑ migration), en-only localisation with the translator agent, and the frontend contract on the `ApiResponse` envelope. All are marked as optional.

TASK-7: PASS
notes: The report follows the existing report layout (header with date/branch/tree/scope/read, Summary, Method, numbered sections). It includes the comparison table, a recommendation explicitly labelled "not a decision", follow-up jobs, and 10 open questions, which include the five scope questions from tasks.md. It says explicitly that no code was changed. No backlog entries were created, as specified.

TASK-8: PASS
notes: `implementation.md` is filled in. I re-ran both commands in the worktree. `composer test` exits 0 (1415 tests, 5091 assertions, no failures). `composer analyse` exits 0 ("No errors"). The suite reports every test as a "warning", but the cause is the sandbox environment, not this branch: `file_get_contents(/workspace/.env)` fails because `.env` is absent and `.env.testing` is a `/dev/null` character device. The uncommitted `tokens.json` and `.env.testing` entries in `git status` are manigot/sandbox artefacts, not developer work.

## Security

Not run separately. This is a docs-only change and adds no code or secrets.

## Overall

APPROVED

Nothing blocks the merge.
