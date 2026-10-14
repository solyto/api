# Brief: Integrations endpoint

type: feature
id: tide
branch: feature/tide_integrations-endpoint
date: 2026-10-14
author: Leander Muskalla

## What
Give the API a single list of instance integrations and tell the frontend which ones are configured.

1. **The list:** a backed enum of integrations, each with an `isConfigured(): bool` that checks the config values the integration needs (same shape as `MusicServiceEnum::isConfigured()`, `app/Api/Libraries/Enums/MusicServiceEnum.php:20-27`):

   | Case | Configured when |
   |---|---|
   | `hardcover` | `services.hardcover.api_key` is filled |
   | `tmdb` | `services.tmdb.access_token` is filled |
   | `discogs` | `services.discogs.access_token` is filled |
   | `bgg` | `services.bgg.api_key` is filled |
   | `spotify` | `services.spotify.client_id` and `client_secret` are filled |
   | `telegram` | `telegram.bots.solyto.telegram_token` and `webhook_token` are filled |
   | `ntfy` | `services.ntfy.url` and `services.ntfy.topic` are filled (same check as `AdminNotification::via()`, `app/Shared/Notifications/AdminNotification.php:18`) |

2. **Endpoint:** `GET /api/v1/integrations` in the `auth:sanctum` group. It returns every case with a boolean, through `ApiResponse::success`:
   `{"hardcover": true, "tmdb": true, "discogs": false, "bgg": true, "spotify": false, "telegram": true, "ntfy": false}`.
   It never returns config values. FormRequest not needed (no input). `@OA` annotation per section 2.

3. **Tests:** one test per case for configured / not configured (set config in the test), one that the response contains only booleans, and one that the endpoint needs auth.

## Why
Concept, agreed with the owner on 2026-10-14:
- **Integration** = an external service the instance is configured for. The admin sets it up at deploy time (Docker secrets via `DockerSecretHelper`), and it applies to all users.
- **Plugin** = something a user turns on, with their own credentials if needed (`user-plugins-storage`).
- **Infrastructure** (Mailgun, imgproxy, `APP_KEY`, webpush) is neither and stays out of this list.

If an integration isn't configured, the frontend must not show the features that depend on it (for example the Telegram option, or Discogs lookups). Today the frontend can't know that. Only music lookups check it internally (`MusicServiceEnum`).

## Out of scope
- Changing how features behave server-side when an integration is missing. They keep their current behavior.
- Moving `MusicServiceEnum::isConfigured()` onto the new enum (possible follow-up, not part of this entry).
- Any admin UI. Integrations stay configured through Docker secrets.
- Frontend changes.

Where it lives (owner, 2026-10-14): the enum is `app/Shared/Enums/IntegrationEnum.php`, since several modules ask it (rule 12.1). The endpoint goes into a new module `app/Api/Integrations/` (Controllers, Tests).

## Notes
The endpoint is additive, so no compatibility concern for 1.0.15 (rule 13.2).

Check: `composer test` and `composer analyse` exit 0.
