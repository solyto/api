# Follow-up: spotify integration rollout (credentials, availability, releases)

Follow-up to the archived `post_spotify-integration` job. Decided and implemented
one point at a time with the author.

## Decisions

1. **localdocker**: add `SPOTIFY_CLIENT_ID` / `SPOTIFY_CLIENT_SECRET` to
   `.env.example` — and `DISCOGS_ACCESS_TOKEN`, which was missing there too.
2. **deployment**: mirror the discogs secret pattern — spotify secrets mounted
   into the `php` service only (queue and dav don't need them; releases stay
   Deezer-only).
3. **frontend availability**: new api endpoint lists the configured music
   integrations; the web app builds its search/import options from it instead
   of hardcoding `[deezer, discogs]`. Mobile copies `app/` on build, so no
   separate mobile change.
4. **releases**: keep Deezer only for now. The dormant Spotify releases path
   stays dormant.

## Changes

- `localdocker` (77d2a91): `.env.example` gains `DISCOGS_ACCESS_TOKEN`,
  `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET`.
- `deployment` (ac4ddf6, 31b25b8): `templates/api/compose.yml.j2` adds
  `SPOTIFY_CLIENT_ID_FILE` / `SPOTIFY_CLIENT_SECRET_FILE` + secret definitions
  for the `php` service. Operators must place `spotify_client_id` and
  `spotify_client_secret` files under `./secrets/` or compose won't start.
- `api` (c302121): new `GET /api/v1/libraries/music/integrations`
  (authenticated) returns `{"integrations": ["deezer", "discogs", "spotify"]}`
  based on configured credentials. `MusicServiceEnum::isConfigured()` (deezer
  always; discogs = token set; spotify = client id + secret set),
  `LibraryMusicService::availableIntegrations()`, controller method with
  OpenAPI annotation, route, and two endpoint tests. Enum case order changed to
  deezer-first so the response order matches the existing UI order.
- `app` (8b1388f): `MusicLibrary` fetches the integrations once in `load()`
  (fallback `['deezer', 'discogs']` on failure); `MusicSearch.svelte` and
  `MusicCreate.svelte` build their source/import lists dynamically from it;
  Spotify icon added; i18n strings (`spotify_import`,
  `spotify_import_validation_error`) in en/de/fr/es + type.

## Known issues / follow-ups

- Deployment is only the template; the actual secret files must be created on
  the host. No ansible task writes secret contents (same as discogs).
- The api `releases` endpoint, `GrabMusicReleases` job, notification dedup and
  the `LibraryMusicRelease` OpenAPI schema are untouched — still Deezer-only
  with integer ids, which is accurate while the provider switch stays off.
- If Spotify releases are ever enabled, the marked switch in
  `LibraryReleases::getMusicReleases()` is the place, and
  `LibraryMusicRelease`'s id/artist_id schemas would need widening to match
  string ids.