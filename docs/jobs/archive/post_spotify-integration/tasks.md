# Tasks: spotify integration

id: post
status: open
analyst:
date: 2026-08-27

<!-- Produced by @analyst from brief.md. -->

## Context found during analysis

- Music library already has two external providers wired through the generic
  routes `GET libraries/music/search/{service}/{query}` and
  `POST libraries/music/import/{service}` (enum-typed `MusicServiceEnum`
  param): `DeezerService` (Http facade, no auth) and `DiscogsService`
  (calliostro client). Both map into `MusicReleaseDTO` / `MusicSearchResultDTO`
  and are dispatched in `LibraryMusicService::search()` / `import()`.
- `QuickAddService` detects/imports music URLs using `MusicServiceEnum::baseUrl()`.
- Releases ("what we do with releases"): `LibraryReleases::getMusicReleases()`
  is hardcoded to Deezer only (favorite artists → `searchArtists` →
  `getNewReleases` → `MusicReleaseDTO`), consumed by the `releases` endpoint
  (cached) and the daily `GrabMusicReleases` job (dedup via DTO `id` +
  `MusicReleaseNotification`). Per author: build out the full Spotify releases
  path; the decision on how to proceed (replace/join/toggle provider) is deferred.
- **Key constraint: Spotify album/artist IDs are 22-char alphanumeric strings;
  both DTOs currently type `id`/`artistId` as `int`.** This forces a DTO type
  widening before the Spotify client can be built.
- Spotify Web API uses OAuth2 client-credentials: the service POSTs
  `SPOTIFY_CLIENT_ID` + `SPOTIFY_CLIENT_SECRET` (loaded via `DockerSecretHelper`
  in `config/services.php`) to `https://accounts.spotify.com/api/token` →
  `access_token` valid 3600s; all data endpoints require `Authorization: Bearer`
  and reuse the cached token until near expiry. The repo has no OAuth token
  pattern yet (Discogs/TMDB use long-lived static tokens, Deezer is public).
  Secrets must come from `DockerSecretHelper`, never `.env`/code.
- Spotify API shape: the full album object (`GET /albums/{id}`) includes a
  `genres` array (often empty); the simplified album objects (search results,
  artist-albums lists) omit `genres`. This is fine: search maps to
  `MusicSearchResultDTO` (no genres field) and the releases flow doesn't set
  genres, so `importFromUrl` reads `genres` directly from the full album
  response with an empty-array fallback.
- `LibraryMusicController::searchAlbumOnDeezer()` is dead code (references
  non-existent `searchOnDeezer()`, not routed, already pinned as known OpenAPI
  drift). Pre-existing; author confirmed it stays untouched.

## Task breakdown

TASK-1: Add `SPOTIFY` case (+ `baseUrl()` -> `open.spotify.com`) to `MusicServiceEnum` and a `spotify` section (`SPOTIFY_CLIENT_ID` / `SPOTIFY_CLIENT_SECRET` via `DockerSecretHelper`) in `config/services.php`
     files: app/Api/Libraries/Enums/MusicServiceEnum.php, config/services.php
     depends: none
     risk: low — additive enum case + config entry; secrets stay in Docker secrets

TASK-2: Widen `id`/`artistId` types on `MusicReleaseDTO` and `MusicSearchResultDTO` from `int` to `int|string` so Spotify string IDs fit
     files: app/Api/Libraries/DTOs/MusicReleaseDTO.php, app/Api/Libraries/DTOs/MusicSearchResultDTO.php
     depends: none
     risk: medium — shared by deezer/discogs/releases paths; existing int callers unaffected, but full music test suite must stay green

TASK-3: Build `SpotifyService` mirroring `DeezerService` (`searchAlbum`, `importFromUrl`, `searchArtists`, `getNewReleases`, `getAlbums`) with OAuth2 client-credentials token fetch + cache and Spotify field mapping (string ids, `release_date` + `release_date_precision`, cover via `images`, album url `https://open.spotify.com/album/{id}`, `genres` from the full album response with `[]` fallback)
     files: app/Api/Libraries/Services/External/SpotifyService.php (new)
     depends: TASK-1, TASK-2
     risk: high — no OAuth precedent in repo (token fetch/expiry/cache mechanism), precision-aware date parsing, `Http` seam for tests; token caching is an implementation detail (Redis via Cache facade with ~55 min TTL or in-memory static)

TASK-4: Inject `SpotifyService` into `LibraryMusicService` and add `SPOTIFY` match arms in `search()`/`import()` (mirroring the deezer arm)
     files: app/Api/Libraries/Services/LibraryMusicService.php
     depends: TASK-1, TASK-3
     risk: low — two new match arms; service container auto-resolves the new dependency

TASK-5: Update OpenAPI annotations: `search/{service}/{query}` and `import/{service}` enums become `{"deezer", "discogs", "spotify"}`; adjust `MusicReleaseImport` schema `id` type in `MusicReleaseResource`
     files: app/Api/Libraries/Controllers/LibraryMusicController.php, app/Api/Libraries/Resources/MusicReleaseResource.php
     depends: TASK-1
     risk: low — annotation-only; `tests/Unit/OpenApiSpecTest.php` drift list must remain unchanged (no new paths added)

TASK-6: Add Spotify URL handling to `QuickAddService`: include `SPOTIFY->baseUrl()` in `detectBasedOnUrl()` music detection and route spotify URLs to `import(MusicServiceEnum::SPOTIFY, ...)` in `commitMusic()` (before the deezer default branch)
     files: app/Shared/Services/QuickAddService.php
     depends: TASK-1, TASK-4
     risk: medium — `commitMusic()` currently defaults non-discogs URLs to deezer; the spotify branch must be ordered before that default

TASK-7: Build out the full Spotify releases capability **dormant** (decision (a)): add a Spotify-based collection path in `LibraryReleases` mirroring the Deezer one (favorites → `searchArtists` → `getNewReleases` → `MusicReleaseDTO` with `provider=spotify`), with its own tests, but do NOT change live behavior — `getMusicReleases()` keeps serving Deezer exactly as today (the cached `releases` endpoint and the daily `GrabMusicReleases` job are unchanged). Structure the code so the later decision (replace/join/toggle) is a small, well-marked switch. Verify `GrabMusicReleases` dedup (strict `in_array` on DTO `id`) already tolerates Spotify string IDs — it does after TASK-2; no behavioral change to the job now
     files: app/Api/Libraries/Services/LibraryReleases.php, app/Api/Libraries/Tests/LibraryServiceTest.php, app/Api/Libraries/Tests/GrabReleasesTest.php
     depends: TASK-1, TASK-2, TASK-3
     risk: low — dormant capability; no change to user-visible releases, caching, or notifications; only risk is scope creep into the live flow, which the task explicitly forbids

TASK-8: Tests: new `SpotifyServiceTest` (Http::fake for token endpoint + search/album/artist-albums endpoints, mirroring the Deezer describe block in `LibraryApiTest.php`), endpoint tests for `search/spotify/{query}` and `import/spotify`, and QuickAdd detect/commit tests for spotify URLs
     files: app/Api/Libraries/Tests/SpotifyServiceTest.php (new), app/Api/Libraries/Tests/LibraryApiTest.php, app/Api/Dashboard/Tests/QuickAddTest.php
     depends: TASK-1, TASK-2, TASK-3, TASK-4, TASK-6
     risk: medium — token endpoint must be faked alongside API endpoints; string-id assertions differ from deezer/discogs

## Decisions / flags (resolved)

1. **Releases:** decided (a) — build the full Spotify releases capability but keep
   it **dormant**: `LibraryReleases` gains a Spotify collection path (favorites →
   `searchArtists` → `getNewReleases` → `MusicReleaseDTO`, provider=spotify) with
   tests, while `getMusicReleases()`, the cached `releases` endpoint, and the daily
   `GrabMusicReleases` job keep serving Deezer exactly as today. The later decision
   (replace Deezer, run both, feature-toggle) is a small, well-marked switch.
   Duplicate-album handling and re-notification semantics are only relevant once
   that switch is thrown.
2. **Genres on import:** no open question. The full Spotify album object
   (`GET /albums/{id}`) includes `genres` (frequently empty) — map them
   directly into `MusicReleaseDTO.genres` with `[]` fallback. No extra artist
   request needed. Simplified album objects (search / artist-albums) omit
   genres, but those paths don't expose genres anyway.
3. **Token caching:** implementation detail, not a decision. Spotify tokens
   expire hourly; the service must fetch once and reuse (Redis via `Cache`
   facade with ~55 min TTL, or an in-memory static) to avoid hitting the token
   endpoint per request. Tests must fake both the token endpoint and the data
   endpoints.
4. **Dead code:** `searchAlbumOnDeezer()` stays untouched.
5. **Frontend "is Spotify configured?" signal:** out of scope for this job,
   tracked as a follow-up. A later task may expose whether Spotify is set up on
   an instance so the frontend can hide spotify UI when it isn't. Backend-side,
   unconfigured instances already degrade gracefully: token fetch fails → 401 →
   service returns null → empty search/import results.