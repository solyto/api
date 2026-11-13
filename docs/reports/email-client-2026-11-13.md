# Report: an email client in solyto

date: 2026-11-13
branch: `report/initiative_email-functionality` (job `initiative`), tree at `ff7ca99`
scope: A user asked for email in solyto, meaning an email client. This report looks at what exists today, the protocols and providers involved, the PHP libraries that could do it, and five ways to build it (or something smaller). It covers security, privacy and the fit with the rest of solyto. It's written for the maintainer, who makes the product decisions. No application code, config, migrations, routes, lang files or `composer.json` were changed. Every new package named here is a candidate that needs approval.
read: false

## Summary

**solyto has no mail-reading code today.** Mail only goes out, as system mail (verification, password reset, account deletion, notification emails) through Mailgun (§1.1). There is no IMAP, POP or JMAP code and no `ext-imap`. The building blocks an email feature would reuse do exist:

- encrypted per-user credentials (`UserPlugin`)
- queued jobs and a scheduler
- `BaseNotification` for alerts
- contacts (in the `pgsql` DAV store), todos, notes and calendars to link mail to

**Four findings shape every option:**

1. **There is no outbound-connection guard (§1.3).** Users would choose IMAP/SMTP hosts and ports. Without a guard, the API server would open TCP connections to any host:port a user names, which is SSRF and internal port scanning. The bot-traffic and external-calendars reports already ask for this guard. For mail it's a hard prerequisite.
2. **Gmail and Outlook/Microsoft 365 no longer accept plain passwords (§2.2).** Google turned off basic auth for IMAP/SMTP for all accounts on 2025-03-14 (app passwords still work for personal accounts with 2-Step Verification). Outlook.com followed on 2024-09-16. A server-side client that wants full Gmail access through OAuth needs Google's *restricted-scope* verification, which includes an **annual third-party security assessment**. That is an ongoing operator cost, not a coding task.
3. **PHP 8.4 no longer ships `ext-imap` (§3.1).** It moved to PECL, and its C library (c-client) hasn't been updated since 2007. A pure-PHP IMAP client is the realistic path. `directorytree/imapengine` (MIT, active, IDLE + XOAUTH2) is the strongest candidate. Sending needs **no new package**: Symfony Mailer is already installed and supports per-user SMTP, including XOAUTH2.
4. **Mail is the most sensitive data solyto would hold (§5).** It includes password-reset links for every other account the user has. Storing it, logging it (Telescope's `MailWatcher` records full raw messages, §5.5) or rendering its HTML unsanitised are each enough to sink the feature. solyto's "private" positioning makes this the deciding factor.

**Options (§4):**

- **A:** stateless IMAP/SMTP proxy
- **B:** sync and cache
- **C:** JMAP / provider APIs
- **D:** solyto-hosted mailboxes
- **E:** light integrations only: a per-user forwarding address that turns mail into todos/notes, through Mailgun inbound routes

**Recommendation (§7, not a decision):**

1. **Start with E.** It's small, it fits solyto's "capture into todos/notes" model, it needs no user credentials, and it needs no new package.
2. **If a real client is wanted, build a "B-lite"** after the SSRF guard: IMAP/SMTP with app passwords, a cached header index, bodies fetched live and not stored, polling instead of IDLE, off by default for self-hosters. Leave Gmail/Microsoft OAuth until the owner accepts the verification cost.
3. **Don't do D.**

The **five open scope questions** from the task list, and a few more, are in §9. Nothing in this report is decided.

No application code was changed in this job.

## Method

- Code was read on this tree. File:line references point at `ff7ca99`.
- `vendor/` was installed from the existing `composer.lock` (`composer install`, no lock change) to read Telescope and to run the test suite. Upstream behaviour quoted from `vendor/` is the pinned version.
- Packagist metadata (`repo.packagist.org/p2/<package>.json`) and the GitHub API were used for library versions, licences, PHP constraints and last activity. Fetched on 2026-11-13.
- Provider and protocol claims come from official pages fetched on 2026-11-13 (URLs inline). Provider policies change often. Anything I couldn't confirm from a primary source is marked **unverified**.
- **Not verifiable here:** the Docker image and PHP extensions (they're defined in the separate solyto/deployment repo), whether imgproxy is set up to fetch remote URLs, and whether the public `user_data` disk is web-served (`storage:link`). Nothing ran against a real mail server.
- `composer test` and `composer analyse` were run at the end of the job (see `implementation.md`).

## 1. Current state (TASK-1)

### 1.1 Outbound mail is system mail only

- `config/mail.php:17` defaults the mailer to `mailgun`, through `symfony/mailgun-mailer` (`composer.json:29`). The from address is `noreply@solyto.app` (`config/mail.php:104-107`).
- Mailgun's secret is read through `DockerSecretHelper` (`config/services.php:42-47`). The helper reads `<NAME>_FILE` and falls back to the plain env var (`app/Shared/Helpers/DockerSecretHelper.php:7-24`). With `APP_DEBUG=true` it returns the env var directly (`:9-11`).
- Senders: three `Mailable`s in `app/Api/Users/Mails/` (`UserVerification`, `PasswordResetMail`, `AccountDeletionMail`), sent from `AuthService.php:44,176`, `PasswordService.php:21` and `UserService.php:177`. Notification emails come from `toMail()` on eight `BaseNotification` subclasses.
- `UserService::mailConfigured()` (`app/Api/Users/Services/UserService.php:240-256`) treats Mailgun as "the only transport the stack wires up".
- Side finding: the `failover` mailer lists an `smtp` mailer (`config/mail.php:74-80`) that isn't defined in `mailers`. It's harmless unless someone picks `failover`. Out of scope, noted only.
- **Nothing reads mail.** No IMAP/POP/JMAP code, no `ext-imap` requirement in `composer.json`, and `php -m` in this sandbox (PHP 8.4.27) has no `imap` or `mailparse`. The lock already contains `symfony/mailer` v7.4.19, `symfony/mime` and `egulias/email-validator` (all transitive through Laravel). It has no HTML sanitiser and no MIME *parser*.

### 1.2 Credential precedent: `UserPlugin`

- `app/Plugins/Models/UserPlugin.php:33` casts `credentials` as `encrypted:array`. That is AES-256 with `APP_KEY`, the same key for every user. It's the only encrypted cast in `app/`. `PluginEnum` has one case, `AI` (`app/Plugins/Enums/PluginEnum.php:7`).
- The API never returns the key: the OpenAPI body marks `api_key` `writeOnly` and "leave it out to keep the stored key" (`app/Plugins/Controllers/UserPluginController.php:107`).
- **APP_KEY rotation:** `UserPluginService::hasReadableCredentials` catches `DecryptException` and treats the plugin as unconfigured (`app/Plugins/Services/UserPluginService.php:143-152`). Laravel tries `APP_PREVIOUS_KEYS` first (`config/app.php:134-136`). So a rotation with previous keys set is transparent. Without them, every stored mail password would silently become unusable, and users would have to re-enter it.

### 1.3 No guard on outbound connections

- `UrlCrawlerService::fetchHtml` does `Http::timeout(3)->get($url)` on any user URL (`app/Shared/Services/UrlCrawlerService.php:41-43`).
- `CalendarImport` sends Basic auth to any user URL (`app/Dav/Services/CalendarImport.php:224-231`) and keeps the import state, **including the plaintext secret**, in the cache for 60 minutes (`:233-236`).
- No private-IP or port check exists anywhere in `app/` (grep for `FILTER_FLAG_NO_PRIV`, `FILTER_VALIDATE_IP`, `ssrf`, `outbound` finds nothing). No backlog entry tracks it (`docs/backlog/` has three unrelated entries).
- HTTP fetches are limited to http(s). An IMAP/SMTP client opens **raw TCP to any port**. A user could aim it at `redis:6379`, `imgproxy:8080` (`config/services.php:63`), the database ports or a cloud metadata address, and read the result from the error message. This is worse than the existing HTTP cases. See `bot-traffic-detection-2026-10-14.md` §2/§7 and `external-calendars-via-dav-2026-11-08.md` §5–§6, which already make the guard a prerequisite.

### 1.4 Modules an email feature would touch

| Area | Where | Why it matters |
| --- | --- | --- |
| Contacts | `app/Api/Contacts/Services/ContactService.php:80-94` (`listContacts`, per address book, cached 24h through `UserCacheService`), `app/Dav/DTOs/ContactDTO.php:18` (`email`) | Recipient autocomplete, "add sender to contacts". Contacts live in the **`pgsql` DAV store** (⚑ reads only, no schema change, §6.1). |
| Todos / Notes | `TodoService::create` (`app/Api/Todos/Services/TodoService.php:154`), `NoteService::create` (`app/Api/Notes/Services/NoteService.php:130`) | Mail → todo / note. |
| Calendars | `CalendarService::createEvent` (`app/Api/Calendars/Services/CalendarService.php:168`), `AppCalendarsPDO::importEventCustom` (`app/Dav/Backend/AppCalendarsPDO.php:431`) | `.ics` invite → event. Writes go to `pgsql` through the existing backend (⚑ data, not schema). |
| QuickAdd / gateway | `app/Shared/Services/QuickAddService.php:39-54`, `app/Shared/Services/IntegrationGateway.php` | STANDARDS §12.8: a cross-module entry point (e.g. inbound mail) goes through the gateway. |
| Export | `app/Api/Export/Services/ExportService.php:12-28` (`FEATURES`), `app/Shared/Models/ExportJob.php` | GDPR export: a `mail` feature would be added here. Export ZIPs are written to the `user_data` disk (`ExportService.php:69-71`), which has `visibility => public` (`config/filesystems.php:41-47`). Whether that path is web-served depends on the deployment (**unverified**). Mail would raise the stakes of that choice. |
| User deletion | `UserService::destroy` (`app/Api/Users/Services/UserService.php:98-152`) | New tables with an FK cascade on `users` are covered. Files on `private_user_data` are covered by `deleteDirectory($user->id)` (`:148-149`). Remote mailboxes aren't touched, and shouldn't be. |
| Notifications | `app/Shared/Notifications/BaseNotification.php:33-78`, `app/Api/Users/Models/UserNotificationSettings.php:22-59` | New-mail alerts: a new type means four columns (⚑ migration). |
| Scheduler / queue | `routes/console.php` (feeds hourly at `:8`), `app/Api/Feeds/Jobs/SyncFeeds.php:16-18` (`$tries = 1`, `$timeout = 60`) | Precedent for periodic per-user outbound work. |
| Inbound webhooks | `routes/web.php:7` (Telegram webhook outside `/api/v1`) | Precedent for an unauthenticated, token- or signature-checked inbound endpoint (option E). |
| Observability | `config/telescope.php:178` (`MailWatcher`), `app/Shared/Providers/TelescopeServiceProvider.php:23-48` | §5.5. |

## 2. Protocols and providers (TASK-2)

All pages were fetched on 2026-11-13.

### 2.1 Protocols

- **IMAP4rev2, RFC 9051** (obsoletes IMAP4rev1, RFC 3501; https://www.rfc-editor.org/rfc/rfc9051.html). IDLE (originally RFC 2177) is part of rev2. Most servers still advertise rev1 plus extensions, so a client must handle rev1 (general knowledge, **unverified** per provider).
- **Incremental sync:** a folder's `UIDVALIDITY` + `UIDNEXT` tell you whether cached UIDs are still valid and which messages are new. **CONDSTORE/QRESYNC (RFC 7162)** add `MODSEQ`, which gives you "flags changed since X" and "UIDs expunged since X" in one round trip. Without them, detecting flag changes and deletions needs a full UID/FLAGS scan of the folder.
- **SPECIAL-USE (RFC 6154)** marks Sent/Drafts/Trash/Junk/Archive. Without it, folder roles are guessed from names.
- **SMTP submission, RFC 6409**, port 587 with STARTTLS, or implicit TLS on 465 (**RFC 8314**, which recommends implicit TLS).
- **JMAP core/mail, RFC 8620/8621:** HTTP + JSON, built-in state strings for delta sync, push via EventSource. Supported by Fastmail (https://www.fastmail.com/dev/: "access mail via JMAP, IMAP, or POP and send via JMAP or SMTP") and some self-hosted servers (e.g. Stalwart, Cyrus; **unverified**). **Not** supported by Gmail, Microsoft or iCloud (**unverified**, no official statement found either way).
- **Provider APIs:** the Gmail API (REST, `history.list` for deltas, push through Cloud Pub/Sub) and Microsoft Graph mail (REST, delta queries, webhooks). Each is a separate adapter with its own app registration (details **unverified**, not fetched).

### 2.2 Provider auth

| Provider | Password / app password | OAuth | Source |
| --- | --- | --- | --- |
| **Google (Gmail, Workspace)** | Basic auth with the account password is **off for all accounts since 2025-03-14** ("CalDAV, CardDAV, IMAP, SMTP, and POP will no longer work with legacy passwords"). **App passwords** still exist for accounts with 2-Step Verification, though Google says they "aren't recommended". Whether Workspace admins can block them is **unverified**. | XOAUTH2 over IMAP/SMTP with scope `https://mail.google.com/`. That scope is **restricted**: apps that access it "from or through a third-party server must undergo an annual security assessment by a Google-approved third party" and must be reverified at least every 12 months. Exceptions include personal use and internal use within an organization. | support.google.com/a/answer/14114704, support.google.com/accounts/answer/185833, developers.google.com/workspace/gmail/imap/xoauth2-protocol, developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification |
| **Outlook.com (personal)** | "Basic Authentication no longer available to access any Outlook account" since **2024-09-16** | OAuth 2 (Microsoft identity platform) required | support.microsoft.com, article "Modern authentication methods now needed…" |
| **Microsoft 365 / Exchange Online** | Basic auth for IMAP/POP was turned off from 2022-10-01. SMTP AUTH basic is still available but Microsoft "has announced plans to retire" it. The retirement timeline "has been updated" and **the current date is unverified**. | OAuth 2 for IMAP/POP/SMTP AUTH. Tenant admins may need to consent (**unverified** for multi-tenant apps). | learn.microsoft.com/…/deprecation-of-basic-authentication-exchange-online |
| **iCloud Mail** | **App-specific password** required. IMAP `imap.mail.me.com`, SMTP `smtp.mail.me.com:587`, SSL required. | no third-party OAuth for mail (**unverified**) | support.apple.com/en-us/102525 |
| **Fastmail** | App passwords for IMAP/SMTP, API tokens for JMAP | OAuth for third-party apps on request (**unverified** terms) | fastmail.com/dev |
| **Self-hosted / small providers** (Posteo, mailbox.org, Proton Bridge, Dovecot…) | Usually password or app password (**unverified** per provider). Proton needs its local Bridge, so a server-side client can't use it (**unverified**). | rarely | n/a |

**What this means:** with app passwords only, solyto can reach iCloud, Fastmail, most small and self-hosted providers, and personal Gmail (if the user makes an app password). It can't reach Outlook.com or most Microsoft 365 tenants. Supporting Microsoft means OAuth. Supporting Gmail without app passwords means OAuth plus Google's annual assessment, because solyto's server would hold the tokens. A self-hoster would have to register their own Google/Microsoft apps, which most won't do.

## 3. PHP libraries and runtime (TASK-3)

Packagist data fetched on 2026-11-13. **All are candidates that need approval. None was added.**

### 3.1 `ext-imap`

- php.net: "This extension has been moved to the PECL repository and is no longer bundled with PHP as of PHP 8.4.0" (https://www.php.net/manual/en/imap.installation.php).
- The unbundling RFC: c-client "is no longer updated. The last release from 2007…" and can't be used with G Suite "due to authentication changes" (https://wiki.php.net/rfc/unbundle_imap_pspell_oci8).
- Using it would mean building a PECL extension in the deployment image (other repo, **unverified** whether feasible) on an unmaintained C library, with no XOAUTH2. **Not recommended.** It also rules out `ddeboer/imap` (requires `ext-imap`, and its latest release needs PHP `~8.5|~8.6`).

### 3.2 IMAP clients (pure PHP)

| Package | Latest | Licence | PHP | Extra deps | Activity | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `directorytree/imapengine` | v1.25.9 (2026-11-04) | MIT | ^8.1 | `symfony/mime`, `nesbot/carbon`, `illuminate/collections`, `zbateson/mail-mime-parser` ^3\|^4 (all but the last already in the app) | last push 2026-11-04, 3 open issues, frequent releases | "without the IMAP extension". Config has `encryption` (default `ssl`), `validate_cert` (default true), `timeout`, `authentication` `plain`\|`oauth`. `ImapConnection` has `login`, `authenticate` (token) and `idle()`. A Laravel integration package exists. CONDSTORE/QRESYNC support: **unverified** (no dedicated methods seen). |
| `webklex/php-imap` | 6.2.0 (2025-04-25) | MIT | ^8.0.2 | carbon, `symfony/http-foundation`, `illuminate/pagination`, `ext-zip`, `ext-fileinfo` | last push 2025-05-07, **150 open issues** | widely used historically. No release in 18 months, so maintenance is a risk. |

MIT is compatible with AGPL-3.0 (permissive licence into a copyleft project).

### 3.3 MIME parsing

- `zbateson/mail-mime-parser` 4.0.9 (2026-11-09), BSD-2-Clause, PHP >=8.1, pure PHP. Comes in through ImapEngine anyway. Needed for option E too, if raw MIME is stored or re-parsed. Mailgun's `forward()` already POSTs parsed fields, so E can run without it.
- `php-mime-mail-parser/php-mime-mail-parser` 10.0.0 needs `ext-mailparse` (PECL), so it has the same image problem as `ext-imap`.

### 3.4 Sending through the user's SMTP: no new package

- `symfony/mailer` (installed, v7.4) builds a transport from a DSN or an `EsmtpTransport`. That transport registers `CramMd5`, `Login`, `Plain` and **`XOAuth2Authenticator`** by default (upstream `Transport/Smtp/EsmtpTransport.php:46-49`, 7.4 branch).
- A per-user send can build the transport in a service and send a `Symfony\Component\Mime\Email` directly, with no `config/mail.php` change. Going through Laravel's `Mail` facade would fire `MessageSent` and land in Telescope (§5.5).

### 3.5 HTML sanitising (incoming mail is hostile HTML)

| Package | Latest | Licence | PHP | Notes |
| --- | --- | --- | --- | --- |
| `symfony/html-sanitizer` | 7.4.23 (2026-11-04); 8.1.11 needs PHP ≥8.4.1 | MIT | 7.4: ≥8.2 | Needs `ext-dom`, `league/uri` (already in the lock) and `masterminds/html5` (7.4 only). Configurable allow-lists for elements, attributes and link/media hosts. It can force external media through a rewrite, which is useful for blocking tracking pixels. Fits the Symfony 7.4 components already used. |
| `ezyang/htmlpurifier` | v4.19.1 (2026-09-15) | **LGPL-2.1-or-later** | up to 8.5 | Mature and strict, slower. LGPL is compatible with AGPL-3.0 (**verify with the owner** if licence policy matters). |

Mail HTML also needs CSS handling (`<style>` blocks, inline styles). Either the sanitiser strips them, or the frontend renders the sanitised HTML inside a sandboxed `iframe` with a strict CSP. That's a frontend decision (§6.4).

## 4. Architecture options (TASK-4)

Common to A–C: a new module `app/Api/Mail/` laid out per STANDARDS §12.1 (Controllers, Models, Requests, Resources, Services, Jobs, Notifications, Factories, Tests), routes in `routes/api.php` under `v1` with Sanctum (§1.4, §12.2), services that take the user as an argument (§4.1), `scopeForUser` on every model (§5.1–5.2), jobs that carry ids (§9.1) with explicit `$tries`/`$timeout` (§9.2), schedules in `routes/console.php` (§9.3/§12.7), new-mail alerts as `BaseNotification` (§9.4). **Every new table is a migration, shown before it's run (hard rule). All of them go on the default MariaDB connection; none touches `pgsql`.**

### Option A: stateless IMAP/SMTP proxy

**What:** solyto stores only the account (host, port, user, encrypted secret). Every API call opens IMAP, does the work, and closes.

- **Data model:** `mail_accounts` (uuid, `user_id` FK cascade, `email`, `display_name`, `imap_host/port/encryption`, `smtp_host/port/encryption`, `auth_type` `password`\|`oauth`, `credentials` `encrypted:array`, `status`, `last_error`, `failures`). One migration.
- **Endpoints:** `GET/POST/PUT/DELETE mail/accounts`, `POST mail/accounts/{id}/test`, `GET mail/accounts/{id}/folders`, `GET mail/folders/{folder}/messages?page=` (paginated by UID range), `GET mail/messages/{id}` (sanitised HTML + text), `PATCH …/flags`, `POST …/move`, `DELETE`, `GET …/attachments/{part}` (streamed), `POST mail/accounts/{id}/send`.
- **Load:** a TLS handshake + LOGIN per request (often hundreds of ms to seconds, **unverified**) inside a PHP-FPM worker. Slow providers tie up FPM workers. No cross-folder search, no unread badges without a connection, no push.
- **Privacy:** best case. Nothing but the credentials is stored. Message ids are `(folder, uidvalidity, uid)` tuples.
- **Effort:** M. Good as a prototype, but it feels slow. Most production webmails keep at least a header cache.

### Option B: sync and cache

**What:** a queued sync keeps a local index (headers, flags, snippets). Bodies are cached or fetched live. The API reads from MariaDB.

- **Data model** (all MariaDB, ⚑ migrations):
  - `mail_accounts` as in A, plus `last_synced_at` and `sync_cursor`
  - `mail_folders`: account, `path`, `role` (SPECIAL-USE), `uidvalidity`, `uidnext`, `highestmodseq`, `unread_count`
  - `mail_messages`: folder, `uid`, `message_id`, `in_reply_to`, `references`/thread key, `from`/`to`/`cc` (json), `subject`, `sent_at`, `snippet`, `flags`, `size`, `has_attachments`, `body_cached_at`
  - bodies: either an `encrypted` text column or files on `private_user_data/{user}/mail/…` (**not** the public `user_data` disk, §1.4)
  - attachments are fetched on demand and streamed, not stored, or stored with a TTL
- **Sync:**
  - per folder, `UIDVALIDITY` changed → drop the folder cache and resync
  - otherwise fetch `UID > last` for new mail
  - with CONDSTORE/QRESYNC: `CHANGEDSINCE modseq` for flags and `VANISHED` for deletes
  - without them: a periodic UID/FLAGS scan, bounded to the last N messages
  - first sync only the last N days or messages per folder
- **Load:**
  - `SyncMailAccounts` scheduled every few minutes, fanning out one job per account with `WithoutOverlapping` per account, staggered, with backoff and auto-disable after N failures (same as the external-calendars report §5.B)
  - **IDLE is not a fit for PHP-FPM or the normal queue worker:** it needs one long-lived TCP connection per watched folder, held for up to 29 minutes. Real-time push would need a dedicated long-running process (an artisan daemon under supervisor, holding many connections in one event loop). That is a new process type in the deployment repo (**unverified** feasibility, not in this workspace).
  - Polling is the realistic first step, at the cost of minutes of delay.
- **Search:** SQL `LIKE` on subject, from and snippet is easy. Full-text search over bodies needs stored bodies, plus server-side IMAP `SEARCH` as a fallback.
- **Endpoints:** as A, reading from the index, plus `GET mail/messages?q=`, `GET mail/unread-counts`, `GET mail/threads/{id}`. Writes (flags/move/delete/send) go to IMAP first, then update the index.
- **Effort:** L–XL. Sync correctness is most of the work: provider quirks, folder renames, UIDVALIDITY resets, big mailboxes, duplicate Message-IDs.
- **"B-lite"** (index headers, flags and snippets; fetch bodies live; never store attachments) keeps most of A's privacy and gets list and unread performance.

### Option C: JMAP / provider APIs

- **JMAP:** much simpler sync than IMAP (state strings, `Email/changes`), HTTP-only, so it fits Laravel's HTTP client with no new IMAP library. But it only covers Fastmail and some self-hosted servers (§2.1).
- **Gmail API / Graph:** better sync and push (Pub/Sub, Graph webhooks need a public HTTPS endpoint). They need the same OAuth app registration and, for Gmail, the restricted-scope assessment (§2.2). That's two more adapters to maintain.
- **Data model:** like B, with `provider` on the account and a per-provider cursor (`jmap_state`, `gmail_history_id`, `graph_delta_link`).
- **Effort:** L per provider on top of a shared core. Makes sense only as extra adapters behind B's model, not as the base.

### Option D: solyto-hosted mailboxes (for contrast)

- solyto becomes a mail provider: MX records, a receiving MTA (Postfix/Stalwart/…), spam and virus filtering, outbound reputation (SPF/DKIM/DMARC, IP warm-up, abuse handling, blocklists), quotas, backups, legal obligations around stored mail (retention, lawful-access requests, **unverified** per jurisdiction).
- Mailgun could receive (routes) and send, but a mailbox product still needs storage, IMAP access for other clients, and abuse handling.
- **Self-hosting:** most self-hosters can't run port-25 mail from home or VPS IPs (often blocked, **unverified** per host).
- **Effort:** XL plus permanent ops. Out of proportion to the request. **Not recommended.**

### Option E: light integrations only

**What:** each user gets a private inbound address (e.g. `u-<random>@in.<domain>`). A Mailgun route `forward()`s mail sent there to a solyto webhook, which turns it into a todo (subject = title, body = description) or a note, or imports an attached `.ics`.

- **Mailgun:** `forward(url)` makes Mailgun "perform an HTTP POST request to the URL which contains a parsed version of the received email", including a `signature` HMAC (https://documentation.mailgun.com/docs/mailgun/user-manual/receive-forward-store/receive-http). Routes filter on `match_recipient()` (…/routes). The webhook checks the signature with a Mailgun webhook signing key read through `DockerSecretHelper` (STANDARDS §12.5).
- **Data model:** `mail_inbound_addresses` (user, `local_part` unique, `target` `todo`\|`note`\|`auto`, `enabled`, `rotated_at`), one migration. Or a column on an existing settings table, a smaller change but less clear.
- **Endpoints:** `GET/PUT mail/inbound` (address, target), `POST mail/inbound/rotate`. The webhook lives in `routes/web.php` next to the Telegram one (`routes/web.php:7`), **not** under `/api/v1`, signature-checked and rate-limited.
- **Security:** no user credentials, no user-chosen hosts, so **no SSRF**. Spam to the address is the main risk. Rotate the address, cap items per day, and only accept mail from the user's verified address(es) (sender-only filters are spoofable; Mailgun's SPF/DKIM results in the payload help, **unverified** which fields).
- **Self-hosting:** needs Mailgun receiving (a paid tier, **unverified**) and an MX for the inbound domain. Off unless configured, like ntfy. Other providers (Postmark/SES inbound) would need their own adapters.
- **Effort:** S–M. Most of the value people describe as "mail in my organiser" (forward a mail → todo) at a fraction of the risk.

### Comparison

| | A: proxy | B: sync/cache (B-lite) | C: JMAP / Gmail / Graph | D: hosted | E: inbound → todo/note |
| --- | --- | --- | --- | --- | --- |
| Is it an email client? | yes, slow | yes | yes, per provider | yes | no |
| Effort | M | L–XL (L) | L per provider | XL + ops | S–M |
| Main risk | slow requests, FPM tied up | sync correctness, stored mail | OAuth verification, upkeep | operations, abuse, legal | inbound spam |
| Privacy | only credentials stored | headers (B-lite) or full mail stored | as B + third-party OAuth | solyto holds all mail | only what the user forwards |
| SSRF guard needed | yes | yes | no (fixed provider hosts) / yes for JMAP | no | no |
| Push for new mail | no | polling; IDLE needs daemon | provider push (needs public endpoint) | native | n/a |
| Self-host impact | none extra | queue load, storage | own OAuth apps | MTA, MX, port 25 | Mailgun inbound + MX |
| New deps (need approval) | IMAP client (+ MIME parser), HTML sanitiser | same | HTML sanitiser (+ OAuth client?) | MTA (deployment) | none (optional MIME parser) |
| New MariaDB tables | 1 | 3–4 | 3–4 | many | 1 |
| `pgsql` / DAV routes | unchanged / unchanged | unchanged / unchanged | unchanged / unchanged | n/a | unchanged / unchanged |

## 5. Security, privacy and compliance (TASK-5)

### 5.1 Credentials and tokens

- **`encrypted:array` (APP_KEY) is the existing pattern** (§1.2). It protects against a DB-only leak, not against someone who has the app server (APP_KEY is in its environment). For mail passwords this is the minimum, not a strong guarantee.
- **Per-user keys** (e.g. derived from the user's login password) would mean the server can't sync while the user is offline, and passkey-only users have no password to derive from. That conflicts with background sync and new-mail alerts. A stateless A could use them. Not recommended for B.
- **APP_KEY rotation:** without `APP_PREVIOUS_KEYS`, all stored mail credentials become undecryptable. Follow the `UserPlugin` precedent (`DecryptException` → "needs re-auth" status, never a 500), and document it for self-hosters.
- **OAuth refresh tokens** are long-lived and broader than an app password (`https://mail.google.com/` is full mailbox access). Same encrypted cast. Refresh access tokens in the job, never in the request path.
- **Never put secrets in the cache.** `CalendarImport` keeps a plaintext secret in the cache for 60 minutes (§1.3). A mail "test connection then save" flow must not copy that.
- **API never returns the secret** (`writeOnly`, as `UserPluginController.php:107`).

### 5.2 SSRF and port scanning

A user-supplied IMAP/SMTP host is a raw-TCP SSRF primitive (§1.3). Minimum guard before any A/B/JMAP job:

- resolve DNS once, reject loopback, private (RFC 1918, ULA), link-local (incl. `169.254.169.254`), CGNAT and reserved ranges, and **connect to the resolved IP** (to prevent DNS rebinding) with SNI and certificate check against the hostname
- **port allow-list:** 993 (IMAPS), 143 (only with STARTTLS required), 465, 587; nothing else
- **TLS only, certificate validation on** (`validate_cert` true), no "accept invalid certs" toggle in the hosted instance. Self-hosters might need one, as an operator config flag, not per user.
- generic error messages (no banner or response text echoed back, which would turn the guard into a port-probe oracle), connection timeouts, and a per-user limiter on "test connection"
- self-hosters whose mail server *is* on a private network need an operator allow-list (config), off by default

### 5.3 HTML, XSS and tracking

- Sanitise **server-side** before the API returns HTML (§3.5), and the frontend renders it in a sandboxed `iframe` with a CSP (no scripts, no forms). Both layers, because the API is also used by other clients.
- **Remote images off by default** (tracking pixels show the sender that, when and from which IP the mail was opened). "Load images" per message or per sender.
- Proxying remote images through **imgproxy** would hide the user's IP. But today imgproxy is only used on `local:///` paths with signed URLs (`app/Shared/Services/Images/ImgproxyDriver.php:18,68,86-97`). Whether the deployed imgproxy may fetch remote URLs is **unverified** (deployment repo). Turning that on makes imgproxy itself an outbound fetcher that needs the same private-IP guard (imgproxy has its own allow/deny settings, **unverified**). The proxy would also still fetch the pixel, so it hides the IP but not the open event.
- Strip or rewrite links (`target=_blank rel=noopener noreferrer`). Optionally warn on mismatched link text.

### 5.4 Attachments and sending abuse

- Size caps per attachment and per message. Stream from IMAP instead of storing. Content-type by sniffing, `Content-Disposition: attachment` for anything not on a safe list. Never serve HTML/SVG attachments inline from the API origin.
- **Malware scanning:** no scanner exists in the stack. ClamAV would be a deployment-repo service (**unverified**). Without it, rely on the user's provider having scanned inbound mail, and say so.
- **Outbound abuse:** sending goes through the *user's* SMTP, so spam damages their own account's reputation, not solyto's IP. But solyto would be an automation surface, so add per-user send limits (`RateLimiter::for('mail-send')` like the existing limiters in `AppServiceProvider.php:53-84`), a recipient cap per message, and no bulk/mail-merge endpoint.
- Option E: per-address daily cap, rotation, sender allow-list.

### 5.5 Logging hygiene

- **Telescope `MailWatcher`** is on (`config/telescope.php:178`) and records the HTML and **full raw message** (`vendor/laravel/telescope/src/Watchers/MailWatcher.php:47-48`) for every `MessageSent` event. Outside `local`, the filter keeps only exceptions, failed requests/jobs, scheduled tasks and monitored tags (`TelescopeServiceProvider.php:23-30`). In `local`, which self-hosters might run, it keeps everything. **User mail must not be sent through Laravel's `Mail` facade**, or `MailWatcher` must be off.
- Failed requests and jobs *are* recorded in production. Telescope hides only `password` / `password_confirmation` parameters and `authorization` / `php-auth-pw` headers by default (`vendor/laravel/telescope/src/Telescope.php:83-96`), plus `_token` and cookies here (`TelescopeServiceProvider.php:42-48`). A failed `POST mail/…/send` would store the message body. A failed account save would store a secret field called anything other than `password`. Add the mail fields to `hideRequestParameters`, keep job payloads to ids (§9.1), and never put credentials or bodies into exception messages.
- `TrackApiTraffic` logs only user id, count and route (`app/Shared/Middleware/TrackApiTraffic.php:35-39`), which is fine. Pulse records route-level aggregates (**unverified** whether it stores request URLs with query strings like `?q=`). Keep search terms out of URLs, or check.
- IMAP library loggers (ImapEngine has `Connection/Loggers`) must stay off in production. Protocol logs contain `LOGIN`/`AUTHENTICATE` lines.

### 5.6 GDPR, export and deletion

- **Deletion:** FK cascade on `users` covers the tables. `private_user_data/{id}` covers files (§1.4). Also clear any per-user cache keys (STANDARDS §8.1, `UserService::forgetUserCache`). Revoke OAuth tokens at the provider on account removal (best effort).
- **Export:** for A/B-lite the mail lives at the provider, so export the *account settings* (without secrets) and maybe a header index. For a full B with stored bodies, an `mbox` export would be expected. Make sure exports with mail content don't land on a public disk (§1.4).
- **Retention:** cached bodies with a TTL. Disabling or removing an account purges its cache at once.
- **Privacy positioning:** solyto calls itself "free, private". A third-party *server* reading users' mail is the opposite of what privacy-minded users expect from a webmail (most mail apps they trust are local clients). Being explicit about what is stored, for how long and where is part of the feature. This favours A/B-lite and E over full B.

## 6. Cross-module integration and frontend contract (TASK-6)

All optional follow-ups, not core scope.

### 6.1 Integrations

- **Recipient autocomplete:** filter `ContactService::listContacts` (cached, §1.4) by name or email in PHP. ⚑ Reads from `pgsql` through `DavService`. No pgsql schema or DAV route change, but for a user with many contacts it loads all address books (24h cache). A dedicated `GET mail/recipients?q=` endpoint keeps it off the contacts endpoints (STANDARDS §13.3).
- **"Add sender to contacts":** `ContactService` create path. ⚑ Writes to `pgsql` (data only).
- **Mail → todo / note:** `TodoService::create` / `NoteService::create` with a link back (`message_id` or a deep link) in the description. Cross-module calls go through a gateway method (STANDARDS §12.8).
- **`.ics` invites (iTIP REQUEST):** import the VEVENT through `AppCalendarsPDO::importEventCustom` (raw vCal, keeps UID/TZ/RRULE; `AppCalendarsPDO.php:431`). ⚑ Writes to `pgsql` (data only). **Replying** (iTIP REPLY: accept/decline mailed to the organiser) is a separate, later step and needs SMTP send.
- **QuickAdd:** "paste a mail" is already plain text → note/todo. No change needed. Option E is QuickAdd by mail.
- **Notifications:** new type `mail_received` → four columns `mail_received_{ui,push,email,telegram}` on `user_notification_settings` (⚑ migration, like `2026_02_18_000000_add_dev_request_comment_notification_settings.php`), plus the model, the `NotificationSettingsController` validation and annotations, the resource and the factory. The `email` channel makes no sense for "you have mail", so default it off and maybe hide it. Push and Telegram payloads should carry the sender name at most, not the subject or snippet, by default (Telegram isn't end-to-end encrypted). Batch: one alert per sync run, not per message. For E, an "item created from mail" alert is optional.
- **Localisation:** new strings (notification titles, sync-failure alert) go in `en` only. The translator agent handles `de/fr/es/it` later (`docs/I18N.md`, STANDARDS §12.10). API `message` strings stay English.
- **Navigation:** a new `mail` feature key in the client-owned `user_settings.navigation` (STANDARDS §12.11). Additive, no migration.

### 6.2 Frontend contract (separate repo)

- Every response uses the `ApiResponse` envelope. Lists use Laravel pagination → `meta` (`app/Api/ApiResponse.php:101-124`). For IMAP-backed lists (A) the "total" is the folder's message count, and pages are UID ranges, newest first.
- Message resource: `id`, `folder_id`, `from` / `to` / `cc` (`{name, email}[]`), `subject`, `sent_at` (ISO 8601), `flags`, `snippet`, `has_attachments`. Detail adds `html` (**sanitised**, with a `remote_content_blocked: true` flag), `text`, `attachments[]` (`part_id`, `filename`, `size`, `mime`).
- Send: `to/cc/bcc`, `subject`, `text`, `html` (sanitised again server-side), `in_reply_to`, attachment uploads through multipart, size limits returned as 422.
- Account status (`ok`, `auth_failed`, `needs_reauth`, `disabled`) so the UI can prompt re-entry after APP_KEY loss or OAuth expiry.
- Old frontends are unaffected: the endpoints are all new (STANDARDS §13.2/13.3).

## 7. Recommendation (not a decision)

1. **Decide the scope first (§9 Q1).** The options differ by an order of magnitude.
2. **Phase 1: option E**, if the Mailgun inbound route is acceptable. It delivers "mail into my organiser" with no stored credentials, no user-chosen hosts and no new package. Off unless the operator configures the inbound domain and signing key.
3. **Phase 2, only if a real client is wanted: B-lite** (IMAP/SMTP, app passwords only, header/flag/snippet index on MariaDB, bodies fetched live and sanitised, attachments streamed, polling every few minutes, no IDLE), behind:
   - the **outbound-connection guard** (§5.2), shared with the external-calendars and bot-traffic work
   - approval of `directorytree/imapengine` (brings `zbateson/mail-mime-parser`) and `symfony/html-sanitizer` 7.4
   - the logging hygiene changes (§5.5)
   - an **operator switch, off by default** for self-hosters (like ntfy and the AI plugin)
4. **Phase 3, optional:** read-only integrations (contacts autocomplete, mail → todo/note, `.ics` import), `mail_received` notifications, a JMAP adapter (Fastmail/self-hosted).
5. **Gmail/Microsoft OAuth only after the owner accepts** Google's annual restricted-scope assessment (or limits Gmail to app passwords) and registers Microsoft/Google apps. Self-hosters would need their own.
6. **Don't do D.**

The reason for this order: E is cheap and safe and may already meet the user's actual need. B-lite gets a usable client with the least stored mail, which suits the "private" positioning. Full B and OAuth bring high fixed costs (sync correctness, assessments) that only make sense with clear demand.

## 8. Suggested follow-up jobs

1. **Outbound-connection guard** (prerequisite for any user-chosen host): IP/range checks, connect-to-resolved-IP, port allow-list, operator allow-list, used by `UrlCrawlerService`, `CalendarImport` and the future mail client. Already proposed in the bot-traffic (§7) and external-calendars (§7.1) reports.
2. **Inbound mail → todo/note (option E):** migration (shown before running), Mailgun webhook in `routes/web.php` with signature check, settings endpoints + OpenAPI, gateway method, tests with faked payloads. Strings in `en`, then the translator agent.
3. **Dependency approval:** decide on `directorytree/imapengine`, `symfony/html-sanitizer` (or HTMLPurifier).
4. **Mail module, B-lite:** accounts (encrypted credentials, test connection), folder/header sync job + schedule, message read (live body, sanitised), flags/move/delete, send through Symfony Mailer, Telescope hygiene, operator switch, export of account settings, tests.
5. **Integrations:** recipient autocomplete, mail → todo/note/event, `mail_received` notification type (⚑ settings migration).
6. **Later / optional:** JMAP adapter, OAuth (Microsoft, then Google), an IDLE daemon (deployment repo), full-body search.

## 9. Open questions for the owner

1. **What does "email client" mean for solyto?** (a) a client for users' *existing* mailboxes (IMAP/SMTP, JMAP, Gmail/Graph APIs); (b) solyto *hosting* mailboxes (own domain, MX, MTA); or (c) light integrations only (forwarding address, mail → todo/note). This report assumes (a) or (c).
2. **Where does mail live?** Proxied live from the provider (nothing stored), a header index only (B-lite), or a full sync/cache including bodies. This decides privacy, storage cost, search, and export/deletion duties.
3. **Provider auth:** app passwords only (no Outlook/most Microsoft 365), or OAuth for Microsoft and/or Google? Google OAuth for full mailbox access means a restricted-scope verification with an **annual security assessment**. Who registers and pays for that?
4. **Self-hosting:** optional and off by default (like Plugins and ntfy)? Should self-hosters get an operator allow-list for private mail servers and a "skip TLS verification" flag?
5. **New dependencies:** may we add `directorytree/imapengine` (+ `zbateson/mail-mime-parser`) and `symfony/html-sanitizer` (or `ezyang/htmlpurifier`, LGPL)? Is a PECL extension in the deployment image off the table, as this report assumes?
6. **Option E:** is Mailgun inbound (paid tier, inbound MX on a solyto subdomain) acceptable, and on which domain?
7. **Real-time:** is polling every few minutes acceptable, or is push wanted? Push means an IDLE daemon in the deployment repo or provider webhooks.
8. **Remote images:** blocked by default with "load images", or proxied through imgproxy (which would then need to fetch remote URLs)?
9. **Exports:** should mail (headers/bodies) be part of the GDPR export, or only account settings? Should export ZIPs move off the public-visibility `user_data` disk regardless (§1.4)?
10. **Who asked, and what for?** If the request is "turn mails into todos", E alone may be enough.
