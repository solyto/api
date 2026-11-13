# Tasks: email functionality

id: initiative
analyst: @analyst
date: 2026-11-13

## Context

- Job type is **report**. The only deliverable is one new file,
  `docs/reports/email-client-2026-11-13.md`, using the same layout as the
  existing reports (e.g. `docs/reports/external-calendars-via-dav-2026-11-08.md`,
  `docs/reports/ntfy-notifications-2026-10-14.md`): header (date/branch/scope),
  Summary, Method, numbered findings with file:line refs, options compared,
  recommendation, phased follow-up jobs, Open questions. **No application code,
  config, migrations, routes, lang files or `composer.json` change in this job.**
- Branch checked: worktree HEAD is `report/initiative_email-functionality`, matches `brief.md`.
- No earlier report, backlog entry or archived job covers email/IMAP (grep of
  `docs/` for `imap|inbox|email client` only matches this brief).

What I found while reading (starting points, not conclusions):

- Outbound mail today is system mail only: `config/mail.php:17` defaults to
  `mailgun`, with `symfony/mailgun-mailer` in `composer.json`. Mailgun keys come
  from `DockerSecretHelper`. Nothing in the app reads mail (no IMAP/POP/JMAP
  code, no `ext-imap` in `composer.json`).
- Credential precedent: `app/Plugins/Models/UserPlugin.php:33` stores per-user
  third-party credentials as `encrypted:array` (APP_KEY-based). This is the only
  encrypted cast in `app/`. Mail account passwords / OAuth refresh tokens would be
  a much more sensitive case of the same pattern. `PluginEnum` has one case (`AI`).
- No SSRF / private-IP guard exists for outbound connections
  (`UrlCrawlerService`, `CalendarImport`, `IntegrationGateway`). User-supplied
  IMAP/SMTP hosts would make this an issue (see also
  `docs/reports/bot-traffic-detection-2026-10-14.md` and the external-calendars report).
- Neighbouring modules an email client would integrate with: `app/Api/Contacts`
  (recipient autocomplete; contacts live in the `pgsql`/DAV store), `Todos` /
  `Notes` / `Calendars` (mail → todo/note, `.ics` invites → calendar),
  `app/Shared/Services/QuickAddService.php`, `app/Shared/Models/ExportJob.php`
  (data export), user deletion in `app/Api/Users/Services/UserService.php`,
  notifications via `app/Shared/Notifications/BaseNotification.php`, scheduler
  in `routes/console.php`, queues on Redis.
- The Docker image / PHP extensions are defined in the separate
  solyto/deployment repo, which is not in this workspace. Whether an IMAP
  extension can be added there can't be verified here.

## Unclear scope (the report must raise these, not decide them)

1. **What "email client" means.** The options are: (a) a client for the user's
   *existing* external mailboxes (IMAP/SMTP, or JMAP, or Gmail/Graph APIs);
   (b) solyto *hosting* mailboxes (own domain, MX, receiving MTA). That is a
   much bigger operational commitment; or (c) light integrations only
   (forward-to-solyto inbound address, mail → todo/note). The brief doesn't say.
2. **Where mail lives.** Proxying live to the provider (nothing stored) or
   syncing/caching messages into solyto's DB/storage. This decides privacy,
   storage cost, search and export/deletion duties.
3. **Provider auth.** Gmail and Microsoft 365 have largely removed basic-auth
   IMAP and require OAuth2 (XOAUTH2), which means app registration and
   verification on the operator side. This needs the owner's decision.
4. **Self-hosting.** Should the feature be optional/off by default for
   self-hosters, like the Plugins and ntfy patterns?
5. **New dependencies.** An IMAP/MIME library (and possibly a PHP extension in the
   deployment image) would be needed. Per project rules that requires explicit
   approval. The report may compare candidates but must not add any.

## Task breakdown

TASK-1: Document the current state: system-only outbound mail (Mailgun config, how secrets are read), the `UserPlugin` encrypted-credential pattern, the missing SSRF guard on outbound connections, and the modules/services an email feature would touch, all with file:line refs.
  files: read-only — config/mail.php, config/services.php, composer.json, app/Shared/Helpers/DockerSecretHelper.php, app/Plugins/**, app/Shared/Services/{UrlCrawlerService,IntegrationGateway,QuickAddService}.php, app/Dav/Services/CalendarImport.php, app/Api/Contacts/**, app/Api/Users/Services/UserService.php, app/Shared/Models/ExportJob.php, routes/console.php
  depends: none
  risk: low — reading and describing existing code only.

TASK-2: Research the protocol and provider landscape from primary sources: IMAP4rev1/rev2 (RFC 3501/9051) incl. IDLE, CONDSTORE/QRESYNC; SMTP submission (RFC 6409); JMAP (RFC 8620/8621); Gmail API and Microsoft Graph mail; OAuth2/XOAUTH2 requirements and basic-auth deprecation at Google/Microsoft; app-password support (iCloud, Fastmail, etc.).
  files: none changed (web research; cite URLs in the report)
  depends: none
  risk: medium — provider policies change often. Date-stamp every claim and mark anything not confirmed from official docs as unverified.

TASK-3: Research the PHP library and runtime options: `ext-imap` status in PHP 8.4 (unbundled to PECL? verify), pure-PHP IMAP clients (e.g. webklex/php-imap, directorytree/imapengine — verify maintenance, licence compatibility with AGPL-3.0, PHP 8.4/Laravel 12 support), MIME parsing (e.g. zbateson/mail-mime-parser), and sending through per-user SMTP with Symfony Mailer (already installed via Laravel) without a new package. Also cover HTML sanitising of incoming mail (e.g. symfony/html-sanitizer, HTMLPurifier).
  files: none changed
  depends: none
  risk: medium — the result points towards new dependencies. The report must present them as candidates that need approval and must not edit composer.json.

TASK-4: Describe the architecture options for the scope variants under "Unclear scope" 1–2: (A) stateless IMAP/SMTP proxy through the API; (B) sync/cache model with queued sync jobs, a header/body cache, attachments on storage, and incremental sync via UIDVALIDITY/MODSEQ; (C) JMAP/provider-API backed; (D) solyto-hosted mailboxes (MX, MTA, spam, deliverability) as a contrast; (E) light integrations only (inbound forwarding address via Mailgun routes → todo/note). For each option give: data model sketch (new module `app/Api/Mail/` per STANDARDS.md layout, migrations needed, stated explicitly as hard-rule items), endpoints under `/api/v1`, queue/scheduler load (IDLE needs long-lived connections, which don't fit PHP-FPM request workers), push notifications for new mail via `BaseNotification`, and rough effort.
  files: none changed (reference docs/STANDARDS.md sections)
  depends: TASK-1, TASK-2, TASK-3
  risk: medium — design judgement and scope creep. The task must stay a comparison of options. Any `pgsql`/DAV involvement (e.g. contacts lookup) must be flagged.

TASK-5: Cover the security, privacy and compliance concerns: credential/OAuth-token storage (`encrypted` cast vs per-user keys, APP_KEY rotation impact), SSRF and port-scan risk from user-supplied hosts (private-IP/port allowlist, TLS-only), HTML/XSS and tracking-pixel handling (sanitise server-side, proxy remote images via the existing imgproxy?), attachment malware/size limits, rate limits and abuse (outbound spam from user SMTP), logging hygiene (Telescope/Pulse must not capture message bodies or credentials), GDPR export (`ExportJob`) and account deletion, and the fit with solyto's "private" positioning.
  files: none changed (reference app/Shared/Services/Images/ImgproxyDriver.php, app/Shared/Middleware/TrackApiTraffic.php, config/telescope.php)
  depends: TASK-1, TASK-4
  risk: high — this is the area most likely to sink or reshape the feature. Every finding must be concrete and must not include secret values.

TASK-6: Describe cross-module integration opportunities and the frontend contract: contacts autocomplete, mail → todo/note/calendar (iTIP `.ics` invites), QuickAdd, notification settings columns for new-mail alerts, localisation needs (en only; other locales go to the translator agent later), and what the separate frontend repo would need (API shape, pagination via `ApiResponse`, sanitised HTML).
  files: none changed (reference app/Api/{Contacts,Todos,Notes,Calendars}/**, app/Api/Users/Models/UserNotificationSettings.php, app/Api/ApiResponse.php, docs/I18N.md)
  depends: TASK-4
  risk: low — descriptive. Keep it as optional follow-ups, not core scope.

TASK-7: Write the report `docs/reports/email-client-2026-11-13.md`: Summary, Method (sources, what couldn't be verified, e.g. the deployment repo's Docker image), sections from TASK-1–6, options comparison table (effort, risk, privacy, self-host impact, new deps), a recommendation clearly labelled as a recommendation, phased follow-up jobs, and the Open questions (at least the five under "Unclear scope"). State explicitly that no code was changed.
  files: docs/reports/email-client-2026-11-13.md (new)
  depends: TASK-1, TASK-2, TASK-3, TASK-4, TASK-5, TASK-6
  risk: low — documentation only. The main risk is presenting unverified claims or a recommendation as a decision.

TASK-8: Fill in `implementation.md` for this job, confirm `composer test` and `composer analyse` exit 0 (required for every job even though no code changes), and commit the report.
  files: docs/jobs/initiative_email-functionality/implementation.md, docs/reports/email-client-2026-11-13.md
  depends: TASK-7
  risk: low — docs only.

## Explicitly not in this job

- Implementing any mail module, migrations, routes, config or lang strings.
- Adding or approving any Composer package or PHP extension.
- Creating backlog entries. List the follow-up jobs in the report only.
