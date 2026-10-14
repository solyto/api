# Report: optional ntfy notifications for operator events

date: 2026-10-14
branch: `report/contain_ntfy-connection` (job `contain`), tree at `3c733ec`
scope: How could solyto send notifications for certain events (e.g. user registration, new dev requests) to an ntfy server, reusing the existing notification channels, without the feature being on by default (especially on self-hosted instances)? Written for the maintainer, who makes the product decisions. This report only describes options. No application code, config, migrations, routes or lang files were changed. Implementation goes to a follow-up job.
read: false

## Summary

**Yes, solyto can send operator notifications to ntfy using its existing notification machinery, and it can be completely off by default.** ntfy's publish API is a single HTTP `POST` with an optional bearer token (§3), so Laravel's HTTP client is enough and no new package is needed. A new `NtfyChannel`/`NtfyMessage` pair can copy the existing `TelegramChannel`/`TelegramMessage` pattern (§1.2).

**Three findings in the current code shape the design:**

1. **`BaseNotification` can't deliver to a non-user target today.** For any notifiable that isn't a `User`, `via()` returns `['database']` (`BaseNotification.php:38-40`), and Laravel silently skips that channel for on-demand notifiables. `resolveLocale()` reads `->settings`, which an anonymous notifiable doesn't have (§1.5). Operator-level delivery needs a small change there.
2. **Notifications are sent synchronously.** Nothing implements `ShouldQueue` (§1.4). If ntfy were wired in the same way, an ntfy outage could slow down or break `POST /auth/register` after the user is already created (§6.2).
3. **There's no event layer**, and dev request creation sends no notification at all today. Every hook would be a direct call from `AuthService` or `DevRequestService` (§2, §1.6).

**Who receives the messages is the main open decision.** The brief's examples (registration, dev requests) are operator events, which points to **option A**: one instance-level ntfy server and topic set by the operator. "Reuse our notification channels" could also mean **option B**: ntfy as a fifth per-user channel next to UI, push, email and Telegram. B needs ⚑ migrations on `user_notification_settings`, API and frontend work, and it creates a server-side request forgery risk because users would choose the URL. **Option C** does A first and B later (§4).

**Recommendation (not decided):** option A with operator notifications extending `BaseNotification`.

- It's enabled only when the operator sets `NTFY_URL` and `NTFY_TOPIC`. There's no default server, so an instance that doesn't set them makes no outbound calls.
- The token is read through `DockerSecretHelper` (§5).
- The notifications are queued and carry minimal content. Email addresses and dev request text shouldn't go to a third-party server like ntfy.sh by default (§6.1).

That's one small follow-up job with no migration (§7). Nine open questions for the maintainer are in §7.

No application code, config, migrations, routes or lang files were changed in this job.

## Method

- Code was read on this tree. File:line references point at `3c733ec`.
- `vendor/` isn't installed in this sandbox, and nothing was installed. Laravel behaviour is quoted from the upstream file at the version `composer.lock` pins (`laravel/framework` v12.58.0), read from the GitHub tag. References like "Laravel `Notifications/NotificationSender.php:116-118`" mean that upstream file at that tag.
- ntfy behaviour comes from the official docs: `docs.ntfy.sh/publish/` (publishing, JSON publishing, auth, caching, Firebase, limits) and `docs.ntfy.sh/config/` (access control), fetched on 2026-10-14.
- Nothing ran against a real ntfy server, and no tests ran. Statements about how the *current* code behaves come from reading it and say so. Anything I couldn't confirm from code or a primary source is marked **unverified**.

## 1. Current state

### 1.1 How a notification picks its channels

Every user-facing notification extends `App\Shared\Notifications\BaseNotification` (STANDARDS §9.4). There are nine of them today, for example `DevRequestCommentNotification`, `FriendRequestNotification`, `ExportReadyNotification`, and the music/book/movie release notifications.

`BaseNotification::via()` (`app/Shared/Notifications/BaseNotification.php:33-78`) works like this:

1. Each subclass names its type with `getNotificationType()`, for example `'dev_request_comment'` (`DevRequestCommentNotification.php:19-22`).
2. **If the notifiable isn't a `User`, it returns `['database']`** (`:38-40`).
3. Otherwise it loads `$user->notificationSettings`, and creates the row if it's missing (`:42-51`).
4. It reads four boolean columns, `{type}_ui`, `{type}_push`, `{type}_email` and `{type}_telegram`, and maps them to `database`, `WebPushChannel::class`, `mail` and `TelegramChannel::class` (`:53-75`). Telegram is only added when the user has a confirmed `telegramConnection` (`:70-74`).

Copy is localised. `withLocale()` (`:24-31`) switches the app locale to `$notifiable->settings?->language` (`resolveLocale`, `:18-22`, which falls back to `en`), and every `toX()` method wraps its `__('notifications.…')` calls in it.

### 1.2 The custom channel pattern: Telegram

Telegram is the only channel the app defines itself. It's the template a ntfy channel would copy:

- `App\Shared\Notifications\Channels\TelegramMessage` is a small builder (`create()`, `line()`, `content()`, `url()`, `getContent()`).
- `App\Shared\Notifications\Channels\TelegramChannel::send(User $notifiable, Notification $notification)` (`TelegramChannel.php:11-30`) checks the user's connection, calls `$notification->toTelegram($notifiable)`, makes sure it got a `TelegramMessage`, and posts it through `TelegramBotService::sendText()`.
- `TelegramBotService::sendText()` (`app/Shared/Services/TelegramBotService.php:16-24`) is a plain `Http::post(...)` and returns `$response->successful()`. **The channel ignores that return value**, so a failed send is silent. A connection error, on the other hand, throws from `Http::post` and isn't caught (reading the code, not tested).
- Each notification implements `toTelegram()` next to `toDatabase()`, `toWebPush()` and `toMail()` (e.g. `DevRequestCommentNotification.php:69-80`).

Two details matter for ntfy:

- `TelegramChannel::send` is type-hinted to `User`. It can't serve an on-demand (non-`User`) notifiable.
- The bot token comes from `DockerSecretHelper::get('SOLYTO_BOT_TELEGRAM_TOKEN')` in `config/telegram.php`. The same file has an operator-level `debug_chat_id` from plain `env('TELEGRAM_DEBUG_CHAT_ID')`. That's a precedent for an operator-only target that sits in config instead of a user's settings.

### 1.3 `user_notification_settings`

`App\Api\Users\Models\UserNotificationSettings` (`app/Api/Users/Models/UserNotificationSettings.php`) has one row per user (`unique('user_id')`) with four boolean columns per notification type: `music_release`, `book_release`, `friend_request`, `daily_day_reminder`, `daily_check_in_reminder`, `calendar_share`, `dev_request_comment`, `export_ready` and `movie_release` (`:21-59`, casts at `:61-100`).

The table was created by `2026_01_07_185012_create_user_notification_settings_table.php`. Every new type since then added its four columns in a migration of its own, e.g. `2026_02_18_000000_add_dev_request_comment_notification_settings.php` (`ui` and `push` default `true`, `email` and `telegram` default `false`).

The API surface is `NotificationSettingsController` (`app/Api/Notifications/Controllers/NotificationSettingsController.php`): `GET`/`PUT /notifications/settings`, with an OpenAPI property per column and the `NotificationSettingsResource`. **A new channel means one new column per type, plus changes to the model, the controller's validation and annotations, the resource, the factory and the frontend.** A new type means four columns.

### 1.4 Queueing: notifications are sent synchronously today

`BaseNotification` uses the `Queueable` trait but **doesn't implement `ShouldQueue`**, and none of the nine subclasses does either (`grep ShouldQueue app` only finds jobs). Laravel only queues a notification when it `instanceof ShouldQueue` (Laravel `Notifications/NotificationSender.php:85`). So every `->notify()` call runs every channel inline, in the request or job that calls it.

For example, `DevRequestService::createComment` (`app/Api/DevRequests/Services/DevRequestService.php:93-98`) sends web push, mail and Telegram inside the `POST /dev-requests/{id}/comments` request. Release notifications are sent from queued jobs (`GrabMusicReleases.php:48` etc.), so they run on the worker, but only because their caller is a job.

The queue itself is Redis by default (`config/queue.php:16`), so queueing notifications is available. It just isn't used for them.

### 1.5 On-demand notifications don't work with `BaseNotification` today

The natural way to send to a target that isn't a user is Laravel's on-demand notification, `Notification::route('ntfy', $target)->notify(new X)`, which uses an `AnonymousNotifiable`. Combined with `BaseNotification`, reading the code (not tested):

- `via()` returns `['database']` for it (`BaseNotification.php:38-40`).
- Laravel skips the `database` channel for an `AnonymousNotifiable` (Laravel `NotificationSender.php:116-118`), so **nothing is sent, and nothing reports it**. `AnonymousNotifiable::route('database', …)` throws outright (Laravel `Notifications/AnonymousNotifiable.php:28-30`).
- `resolveLocale()` reads `$notifiable->settings`, a property `AnonymousNotifiable` doesn't have. Reading an undefined property is a PHP warning, and Laravel turns warnings into exceptions. So calling `withLocale()` with an anonymous notifiable would most likely throw (**unverified**).

So operator-level delivery through `BaseNotification` needs a change to `via()` and `resolveLocale()`, or a notification that doesn't extend it (§4).

### 1.6 No event/listener layer

There are no `Event` classes, `Listener`s or `Event::listen` calls in `app/`. The only model hook is `UserObserver` (`app/Api/Users/Observers/UserObserver.php`, registered in `AppServiceProvider.php:41`). Its `created()` sets up the profile, settings, default DAV calendar/address book and journal category. Side effects are called directly from services (e.g. `DevRequestService::createComment`), and that's the pattern a ntfy hook would follow unless the maintainer wants events (open question 5).

## 2. Candidate events and hook points

The brief names "user registration" and "dev requests" as examples. These are all the places I found where such events happen, plus a few nearby ones the maintainer may want. I searched for every `User::create` and `DevRequest::create` in `app/`. Nothing here is decided; open question 2 asks which events to support.

| # | Event | Where it happens | Hook point | Notes |
| --- | --- | --- | --- | --- |
| E1 | **User registered (self-service)** | `POST /v1/auth/register` (`routes/api.php:70`, throttled to 10/hour per IP in `AppServiceProvider.php:48-50`) → `AuthController::register` (`app/Api/Users/Controllers/AuthController.php:59-64`) → `AuthService::register` (`app/Api/Users/Services/AuthService.php:15-46`) | After the verification mail is sent (`AuthService.php:43`), before `return` (`:45`) | The user can still be deleted when token creation fails (`:38-41`), so the hook belongs at the end, not right after `User::create` (`:17`). The mail is sent synchronously too, so the request already depends on one external service. |
| E2 | **User created by an operator** | `php artisan` `CreateUser` command (`app/Api/Users/Commands/CreateUser.php:44-50`) | After `User::create`, before the `info()` line (`:52`) | The operator ran the command, so an extra ntfy message is probably noise. It's listed so the "every creation path" question is answered explicitly (open question 2). |
| E3 | User created via `UserService::create` | `app/Api/Users/Services/UserService.php:68-71` | — | **No caller found.** `UserController` only exposes `index` and `update` (`routes/api.php:119`). Dead path today, listed for completeness. |
| E1/E2 alt. | Any user created | `UserObserver::created` (`app/Api/Users/Observers/UserObserver.php:13-19`) | Add a call at the end of `created()` | Catches E1, E2 and any future path in one place. The downside is that it fires for E2 (operator) and for factories in tests, and it fires *before* `AuthService` has finished (verification token, mail), so a user that's rolled back at `AuthService.php:39` would still be announced. |
| E4 | **User verified their email** | `POST /v1/auth/verify` → `AuthController::verify` (`AuthController.php:237-256`) → `AuthService::verify` (`AuthService.php:81-111`) | After `$verificationToken->delete()` (`:108`) | A useful alternative to E1. It filters out sign-ups that never confirm (spam, typos). |
| E5 | **Dev request created** | `POST /v1/dev-requests` (`routes/api.php:305`) → `DevRequestController::store` (`app/Api/DevRequests/Controllers/DevRequestController.php:91-99`) → `DevRequestService::create` (`app/Api/DevRequests/Services/DevRequestService.php:21-37`) | After the screenshot is saved and before `return` (`:34-36`) | **No notification of any kind is sent here today.** Note: `created_by_user_id` comes from the request body (`StoreDevRequestRequest.php:19`, nullable), not from the authenticated user, so a message shouldn't present it as a verified author. |
| E6 | Dev request commented | `DevRequestService::createComment` (`DevRequestService.php:83-101`) | Next to the existing `DevRequestCommentNotification` (`:93-98`) | The author already gets a per-user notification. For the operator this is probably noise unless the commenter isn't an admin. |
| E7 | Dev request updated / status changed | `DevRequestController::update` (`DevRequestController.php:153-160`, admin-only via `abort_unless($this->isAdmin(...))`) → `DevRequestService::update` (`:39-45`) | — | Only admins do this, so the operator already knows. More useful as a *per-user* notification to the author (option B), which is a different feature. |
| E8 | Dev request voted | `DevRequestService::vote` (`:52-73`) | — | High volume, low value. Listed only for completeness. |
| E9 | User deleted (operator) | `DeleteUser` command (`app/Api/Users/Commands/DeleteUser.php:28`) → `UserService::destroy` (`UserService.php:89`) | — | The operator ran the command. Not a good candidate. |
| E10 | Export failed | `ProcessExport` job catch block (`app/Api/Export/Jobs/ProcessExport.php:45-56`) | Next to the existing failure notification (`:53`) | An operational signal, outside the brief's "e.g." list. It would open a new category ("operational alerts": failed jobs, failed feed syncs) that the maintainer may or may not want (open question 2). |

There is **no event/listener layer** (§1.6). Every hook above would be a direct call from the service, which is how `createComment` already does it. Introducing Laravel events (`UserRegistered`, `DevRequestCreated`) with a listener that sends to ntfy is possible, but it would be a new abstraction for the codebase, which the project rules discourage unless asked for.

## 3. The ntfy publish API

All of this comes from `docs.ntfy.sh/publish/` and `docs.ntfy.sh/config/` (§Method).

**Publishing.** A message is an HTTP `PUT` or `POST` to `{server}/{topic}`, with the message as the body. Topics are created on the fly. Topic names may only contain `[-_A-Za-z0-9]` and are at most 64 characters long. Optional metadata goes in headers:

| Header (aliases) | Meaning |
| --- | --- |
| `X-Title` (`Title`, `ti`, `t`) | Notification title. Defaults to the topic URL. |
| `X-Priority` (`Priority`, `prio`, `p`) | `1`/`min` … `3`/`default` … `5`/`max`/`urgent` |
| `X-Tags` (`Tags`, `tag`, `ta`) | Comma-separated. Tags that match an emoji short code (e.g. `tada`, `warning`) become emojis. |
| `X-Click` (`Click`) | URL opened when the notification is tapped |
| `X-Markdown` (`Markdown`, `md`) | `true` to render the body as Markdown. Plain text is the default. |
| `X-Cache` (`Cache`) | `no` = don't store the message on the server (§6.1) |
| `X-Firebase` (`Firebase`) | `no` = don't forward to Firebase Cloud Messaging (§6.1) |

The docs note that non-ASCII header values may need RFC 2047 encoding with some HTTP libraries. The Laravel HTTP client's behaviour there is **unverified**, and it's one reason to prefer JSON.

**Publishing as JSON.** Alternatively, `POST` a JSON object to the server **root URL** (not the topic URL). Only `topic` is required. Optional fields include `message`, `title`, `tags` (array), `priority` (int 1-5), `click`, `markdown`, `icon` and `actions`. This avoids header encoding issues and maps directly onto `Http::withToken($token)->post($server, [...])`. The docs list no JSON field for cache or Firebase, so those would stay headers (**unverified** whether JSON publishing honours them: the docs show them only as headers and query params).

**Authentication.** Depending on server config, a topic can be open or protected:

- An access token as `Authorization: Bearer tk_…`. Tokens are created with `ntfy token` or in the web app's Account section.
- Username and password as HTTP Basic auth, or a token as the Basic password with an empty username.
- The same values as an `?auth=` query parameter. That's not recommended here, because query strings end up in logs.

**ntfy.sh versus self-hosted.** On ntfy.sh, "all topics … are public": anyone who knows or guesses the topic name can subscribe, so "the topic is essentially a password". A self-hosted server is open read-write by default too (`auth-default-access` defaults to `read-write`). For a private instance the docs recommend `auth-default-access: deny-all` plus users/ACLs and tokens.

**Limits.** The maximum message size is 4,096 bytes; larger bodies are turned into attachments. By default, messages are cached on the server for 12 hours. Other rate limits are server-configurable and only matter at volumes far above what these events produce.

**No package needed.** The API is a single HTTP call. Laravel's HTTP client (already used by `TelegramBotService`) covers it, so a later implementation doesn't need to ask for a new dependency. Community Laravel ntfy channel packages exist, but I didn't evaluate them (**unverified**), and they aren't needed.

## 4. Delivery options

"Reuse our notification channels" can mean two different things, and the brief's examples point at only one of them (open question 1):

- **Operator events** (a user registered, a dev request came in) concern whoever *runs* the instance. They have no natural "user" recipient.
- **Per-user ntfy** would be a fifth channel next to UI, push, email and Telegram, which each user configures for their own notifications.

All three options share the same building blocks, modelled on Telegram (§1.2):

- `App\Shared\Notifications\Channels\NtfyMessage`: a builder with `title()`, `body()`, `priority()`, `tags()`, `click()` and `toArray(string $topic)`, producing the JSON publish body (§3).
- `App\Shared\Notifications\Channels\NtfyChannel::send(object $notifiable, Notification $notification)`: calls `$notification->toNtfy($notifiable)`, resolves the target, and `POST`s the JSON to the server root with Laravel's HTTP client (`Http::timeout(…)->withToken($token)->post(...)`), only adding the token when one is configured. **No new package.**

### Option A: one operator-level target via on-demand notifications

The instance has one ntfy server and topic, set by the operator in config (§5). The hooks from §2 send an on-demand notification:

```php
Notification::route(NtfyChannel::class, config('services.ntfy.topic'))
    ->notify(new UserRegisteredNotification($user));
```

`NtfyChannel` reads the topic from `$notifiable->routeNotificationFor(NtfyChannel::class, $notification)` and the server/token from config.

**`BaseNotification` needs a change**, because today it would silently send nothing to an `AnonymousNotifiable` (§1.5). There are two ways:

- **A1, operator notifications extend `BaseNotification`.** `via()` gets a branch for non-`User` notifiables, e.g. "an `AnonymousNotifiable` with an ntfy route → `[NtfyChannel::class]`, otherwise `[]`", replacing the current `['database']` fallback, which can never deliver anyway. `resolveLocale()` has to stop reading `->settings` on something that isn't a `User` (`$notifiable instanceof User ? … : 'en'` or a config locale, §6.3). This keeps STANDARDS §9.4 ("user-facing messages are Notifications extending `BaseNotification`") literally intact.
- **A2, operator notifications extend Laravel's `Notification` directly**, with their own `via()` returning `[NtfyChannel::class]`. `BaseNotification` stays untouched. Whether operator alerts count as "user-facing messages" under §9.4 is a standards question for the maintainer (open question 6). If they do, A2 needs a STANDARDS decision entry.

Files a later implementation would touch (A1):

| File | Change |
| --- | --- |
| `app/Shared/Notifications/Channels/NtfyChannel.php`, `NtfyMessage.php` | new |
| `app/Shared/Notifications/BaseNotification.php` | non-`User` branch in `via()`, safe `resolveLocale()` |
| `app/Api/Users/Notifications/UserRegisteredNotification.php` (E1/E4) | new, `toNtfy()` only |
| `app/Api/DevRequests/Notifications/DevRequestCreatedNotification.php` (E5) | new, `toNtfy()` only |
| `app/Api/Users/Services/AuthService.php`, `app/Api/DevRequests/Services/DevRequestService.php` | one call each at the hook points from §2 |
| `config/services.php` | `ntfy` block (§5) |
| `resources/lang/{en,de,fr,es}/notifications.php` | only if operator copy is localised (§6.3) |
| tests in `app/Api/Users/Tests`, `app/Api/DevRequests/Tests`, plus a channel test | §6.4 |

- **Migration: none.**
- **New package: none.**
- **API/OpenAPI/frontend: none.** The operator configures it, so there's no endpoint.

The "send only if enabled" check needs a home. Without adding a new service, the cleanest place is `via()`: if ntfy isn't configured, it returns `[]`. Laravel then neither sends nor queues anything, because `via()` runs before queueing (Laravel `NotificationSender.php:223`). Each hook still has its one `Notification::route(...)->notify(...)` line, but with the check in `via()` that line does nothing when ntfy is off, even if the configured topic is empty. Whether that repetition across two or three hooks is acceptable, or whether a small helper is wanted, is a call for the maintainer, because the project rules discourage new abstractions (open question 5).

### Option B: per-user ntfy channel

Each user can set their own ntfy server, topic and optional token, and choose ntfy per notification type, just like Telegram. Operator events would then become notifications to admin users (`User::isAdmin()`, `app/Api/Users/Models/User.php:67-70`) with new types such as `user_registered` and `dev_request_created`.

What it needs:

- **⚑ Migrations (hard rule: show before running):**
  - `{type}_ntfy` boolean columns on `user_notification_settings` for all nine existing types, default `false`.
  - Four columns per new admin type (`user_registered_*`, `dev_request_created_*`), which only make sense for admins but would exist on every user's row.
  - Storage for the user's target: a new table (like `telegram_bot_connections`) or columns, with the user's ntfy token stored with Laravel's `encrypted` cast. This is user data, not a Docker secret.
- `BaseNotification::via()`: `$ntfyKey = "{$type}_ntfy"` → `NtfyChannel::class` when the user has a target.
- `toNtfy()` on all nine existing notifications, plus lang keys if new copy is needed (most can reuse the existing `*_title`/`*_body` keys).
- `UserNotificationSettings` fillable/casts, `NotificationSettingsController` (validation and OpenAPI annotations), `NotificationSettingsResource`, the factory, the existing notification API tests, and a new controller/request/resource for managing the ntfy target, modelled on `TelegramBotConnectionController`.
- Frontend work in the separate repo.

**⚑ Security: B makes the server send HTTP requests to user-chosen URLs.** That's a server-side request forgery surface. A user could point it at internal services such as `http://imgproxy:8080` (`config/services.php:77`), Redis or the metadata endpoint. B would need an allow-list (e.g. only the operator's ntfy server and/or ntfy.sh) or strict URL validation that rejects private addresses. Option A doesn't have this, because only the operator sets the URL.

B delivers the brief's examples only indirectly (via admin users), and is much larger: migrations, API, frontend, all nine notifications.

### Option C: both

Option A first for operator events. Option B later, if users ask for ntfy as a personal channel. The two share `NtfyChannel` and `NtfyMessage`. The channel resolves the target from the on-demand route (A) or from the user's connection (B). The `BaseNotification` change from A1 is the same either way.

### Comparison

| | A (operator target) | B (per-user channel) | C (both) |
| --- | --- | --- | --- |
| Delivers "notify me on registration / dev request" | directly | via admin users | directly (A part) |
| Migration | none | ⚑ yes, several | ⚑ yes (B part) |
| API / OpenAPI / frontend | none | yes | yes (B part) |
| Off by default | unset config = off (§5) | columns default `false` | both |
| SSRF surface | no (operator URL) | ⚑ yes (user URL) | yes (B part) |
| New package | no | no | no |
| Rough size | small | large | small, then large |

## 5. Off by default: configuration

The brief says the feature "should not be auto-on, especially for self-hosted instances". With option A, that can be guaranteed by config alone. This is a sketch for a later job. Nothing was added to `config/services.php`, and the names are placeholders:

```php
// config/services.php (sketch, not implemented)
'ntfy' => [
    'url'    => env('NTFY_URL'),                                            // no default
    'topic'  => env('NTFY_TOPIC'),                                          // no default
    'token'  => \App\Shared\Helpers\DockerSecretHelper::get('NTFY_TOKEN'), // optional
    'events' => [
        'user_registered'     => env('NTFY_EVENT_USER_REGISTERED', true),
        'dev_request_created' => env('NTFY_EVENT_DEV_REQUEST_CREATED', true),
    ],
],
```

**Semantics:**

- **Enabled only if `url` and `topic` are both non-empty.** Neither has a default. In particular, **there is no fallback to `https://ntfy.sh`**, so an instance only talks to a server its operator chose. An instance without these values never builds an ntfy message, never queues a job and never makes an outbound call (via `via()` returning `[]`, §4).
- An extra explicit flag (`NTFY_ENABLED=true`) is the alternative. It makes "on" a deliberate act even when a URL is left over from a template, but it's one more variable. Either way is "not auto-on". Open question 3.
- **Per-event toggles** let an operator keep e.g. dev requests but mute registrations. They only matter once url and topic are set. Whether they default to on (as sketched) or off is open question 3.
- **Self-hosted instances:** if the operator does nothing, nothing changes. There's nothing to opt out of. Solyto's own hosted instance would set the variables in its deployment (the separate `solyto/deployment` repo), so the hosted product has no code paths that self-hosters don't.

**Secrets:**

- The **access token goes through `DockerSecretHelper`**, like every other third-party credential in `config/services.php` (Hardcover, TMDB, Mailgun, …), and never through `.env`. `DockerSecretHelper::get()` (`app/Shared/Helpers/DockerSecretHelper.php:7-20`) reads `NTFY_TOKEN_FILE` in production, reads the plain `NTFY_TOKEN` variable only when `APP_DEBUG` is `"true"`, and returns `null` when neither is set. A `null` token means "send without auth", which is right for an open self-hosted topic.
- **The topic name can be a secret too.** On ntfy.sh, and on any self-hosted server left at the default `read-write` access, whoever knows the topic name can read every message (§3). If the operator uses such a server without a token, the topic should also come from `DockerSecretHelper` instead of `env()`, or the docs should say plainly that it must be unguessable. Open question 7.
- The token must not be logged. If `NtfyChannel` reports a failed response, it should log the status and the server host, not the request headers or the full URL. (`?auth=` is never used, so the URL itself holds no secret.)
- This report contains no real URL, topic or token, and nothing was written to `.env` or `.env.testing`.

## 6. Cross-cutting concerns

### 6.1 Privacy

solyto presents itself as a *private* app, so what leaves the instance is the main design question, not a detail. With an operator target, every message goes to a server outside the app. On ntfy.sh that's a third party, and from there it can go to Google's Firebase for Android delivery (§3).

Data the candidate events could include:

| Event | Possible content | Sensitivity |
| --- | --- | --- |
| E1/E4 registration / verification | name, email address, user id, time | Email and name are personal data |
| E5 dev request created | type, title, description, URL, screenshot, the claimed author | Title and description are free text written by a user. They can hold anything, including personal data or security reports. |

Options to reduce it, none decided (open question 4):

- **Minimal content by default:** "New user registered" or "New bug report: \<title\>", with no email, no name, no description and no screenshot. A click URL to the admin/dev-request page lets the operator see the details inside solyto, where they're access-controlled. (There's no admin user page in this API today, so for E1 the link target is open.)
- **Avoid server-side storage:** send `X-Cache: no` (ntfy doesn't store the message) and `X-Firebase: no` (no copy to Firebase). The docs warn that without Firebase, delivery to the Google Play Android app can be delayed by up to 15 minutes unless "instant delivery" is turned on in the app.
- **Recommend a self-hosted ntfy server with access control** (`auth-default-access: deny-all` + token) in the operator docs, and describe ntfy.sh as "works, but messages pass through a third party".
- **Privacy policy:** if solyto's hosted instance sends user data such as email addresses to ntfy.sh, that's a new recipient of personal data and probably belongs in solyto's privacy policy. That's a call for the maintainer, not something this report can settle.

Two smaller points:

- `created_by_user_id` on dev requests is client-supplied (§2, E5). A message shouldn't present it as the verified author.
- ntfy's limit is 4,096 bytes per message (§3). Free text should be truncated anyway.

### 6.2 Failure handling

Today every notification runs inline (§1.4). If ntfy were added the same way:

- Registration (`POST /auth/register`) and dev request creation would make an HTTP call to ntfy inside the request.
- Laravel's HTTP client doesn't throw on 4xx/5xx unless `->throw()` is called, but it *does* throw a `ConnectionException` on DNS errors, refused connections and timeouts. Its default timeout is 30 seconds (**unverified** for this version: Laravel's documented default).
- So with sync delivery, an ntfy outage could slow registration down by up to 30 s or make it fail with a 500 *after* the user was created and the verification mail was sent (reading the code, not tested). That's the wrong trade-off for an optional side channel.

What a later implementation can do, in increasing robustness:

1. **Make the ntfy notifications `ShouldQueue`.** Only the new ones, not `BaseNotification` as a whole, because that would change how all nine existing notifications are delivered. The HTTP call then runs on the Redis queue worker, and a failure ends up in `failed_jobs` instead of the user's response. Because `via()` returns `[]` when ntfy is off, nothing is queued on instances that don't use it (§4). Retries and timeout get explicit `$tries`/`$timeout` with a reason (STANDARDS §9.2).
2. **Carry scalars, not models** (STANDARDS §9.1): e.g. the user id and the dev request title, so a queued notification doesn't fail if the row is gone by the time it runs.
3. **Short HTTP timeout in `NtfyChannel`** (a few seconds) and `report()` on a non-2xx response, without the token (§5). That's better than `TelegramChannel`, which drops the result silently (§1.2).

Note: tests run with `QUEUE_CONNECTION=sync` (`phpunit.xml:27`), so queued notifications still run inline in tests, and `Http::fake` covers them.

### 6.3 Localisation

STANDARDS §9.4 and §12.10 require user-facing copy from `resources/lang/{en,de,fr,es}` through `__()`, with no literal strings in notifications. For operator messages:

- **Lang keys in all four languages** (e.g. `notifications.ntfy_user_registered_title`) keep the rule literal. The locale has to come from somewhere other than a user: `config('app.locale')` or a `services.ntfy.locale`. `resolveLocale()` needs the non-`User` fix from §4 either way.
- **English-only literals** would be simpler, since the reader is the operator, not a user. But that needs a STANDARDS decision entry, like the existing one that keeps API envelope messages in English (§12.10).

Open question 6. If the existing comment keys can be reused, that's two or three new keys per language.

### 6.4 Testing approach (docs/TESTING.md)

Following TESTING.md ("External services — through the HTTP seams with `Http::fake()` … never a live call"; "assert the effect, not that the job was dispatched"):

- **Off means off:** with no `services.ntfy` config, `Http::fake()`, `POST /auth/register` and `POST /dev-requests`, then `Http::assertNothingSent()`. The mailer is `array` in tests (`phpunit.xml:26`), so any HTTP request at that point would be ntfy. This is the test that guarantees self-hosted instances make no call.
- **On sends the right thing:** set config, `Http::fake()`, trigger the hook, then `Http::assertSent()`: a `POST` to the server root, the JSON `topic`, `title` and `message`, no email or description in the body (the privacy choice from §6.1), and `Authorization: Bearer` only when a token is configured.
- **Per-event toggle:** event disabled → nothing sent.
- **Failure doesn't break the request:** `Http::fake([... => Http::response(null, 500)])` and a `ConnectionException` fake. Registration still returns 201 and the user exists.
- **Hook wiring**, if wanted on top of that: `Notification::fake()` + `Notification::assertSentOnDemand(UserRegisteredNotification::class, …)` checks the route, with no HTTP involved.
- **`BaseNotification` change:** existing `User` notifications still resolve their channels from settings (the current `NotificationApiTest`/`CommandTest` cover that), and an anonymous notifiable no longer gets `['database']`.

Tests would live with the module (`app/Api/Users/Tests`, `app/Api/DevRequests/Tests`), and the channel test in `tests/Unit` or next to `BaseNotification`.

## 7. Recommendation (for decision, not decided)

**Option A, variant A1:** one operator-level ntfy target, on-demand notifications through a new `NtfyChannel`/`NtfyMessage` modelled on Telegram, and operator notifications extending `BaseNotification`, with `via()`/`resolveLocale()` fixed for non-`User` notifiables. My reasons:

- It's what the brief's examples describe: the operator hears about sign-ups and dev requests.
- It reuses the existing channel machinery (`BaseNotification`, the custom channel pattern, `toX()` methods, lang files) instead of building around it.
- It needs no migration, no API change, no frontend work and no new package.
- It's off unless the operator sets a URL and a topic. Self-hosted instances that don't configure it make no outbound call.
- It has no SSRF surface, because only the operator sets the URL.

Option B stays available later (option C), and the channel and `BaseNotification` work from A carries over.

### Phased plan

- **Phase 1 (one follow-up job):**
  - `NtfyChannel` + `NtfyMessage`.
  - The `services.ntfy` config with the token via `DockerSecretHelper`.
  - The `BaseNotification` non-`User` fix.
  - Two queued (`ShouldQueue`) operator notifications: registration (E1 or E4, open question 2) and dev request created (E5), each with minimal content and `X-Cache: no`.
  - Lang keys or a STANDARDS decision (open question 6).
  - The tests from §6.4.
  - A short operator note: how to enable it, and the ntfy.sh vs self-hosted privacy trade-off.
- **Phase 2 (optional):** more operator events the maintainer picks from §2, e.g. operational alerts (E10).
- **Phase 3 (optional, only if users ask for it):** option B, a per-user ntfy channel. ⚑ Migrations on `user_notification_settings` plus a target table, an SSRF allow-list, API and frontend.

### Risks

1. **`BaseNotification::via()` is shared by all nine notifications.** The change must only touch the non-`User` branch. Existing channel resolution for users is covered by the notification tests, and they must stay green.
2. **Sync delivery:** if the new notifications aren't queued, an ntfy outage can slow down or break registration (§6.2).
3. **Privacy:** content that's too rich (email, dev request text) sent to ntfy.sh and Firebase (§6.1).
4. **Topic as a password:** a guessable topic on an open server lets strangers read operator alerts (§5).

### Open questions for a human

1. **Who receives the messages?** One operator-level target (A), a per-user ntfy channel (B), or both (C)? The brief's examples point at A, and "reuse our notification channels" could mean either.
2. **Which events?** Registration as soon as the account exists (E1), or only once the email is verified (E4)? Dev request created (E5)? Should comments, status changes or votes (E6-E8), operator CLI actions (E2, E9) or operational alerts like failed exports (E10) be included?
3. **What does "not auto-on" mean exactly?** Is "off unless URL and topic are set" enough, or is an explicit `NTFY_ENABLED` flag wanted? With ntfy configured, should per-event toggles default to on or off?
4. **What may leave the instance?** Email addresses and names on registration? Dev request titles or descriptions? Should `X-Cache: no` and `X-Firebase: no` be defaults? Does the hosted instance's privacy policy need an update if it uses ntfy.sh?
5. **How should hooks be wired?** As direct calls in `AuthService`/`DevRequestService` (current pattern), via `UserObserver`, or by introducing Laravel events/listeners (new to this codebase)? Is a small helper for "send to the operator if enabled" acceptable, or should each hook call `Notification::route(...)` directly?
6. **Standards:** should operator notifications extend `BaseNotification` (A1, needs the `via()`/`resolveLocale()` change) or Laravel's `Notification` (A2, needs a STANDARDS decision)? Should their copy be localised in all four languages or English-only?
7. **Is the topic name a secret?** Should it be read through `DockerSecretHelper` like the token, or is `env()` fine because operators are expected to use a protected server?
8. **Queueing:** is it OK to make only the new ntfy notifications `ShouldQueue` and leave the existing nine synchronous? (The silent failure in `TelegramChannel`, §1.2, is a separate issue and out of scope here.)
9. **Should `docs/backlog/ntfy-notifications.md`** (empty today) be filled from this report? This job didn't touch it.
