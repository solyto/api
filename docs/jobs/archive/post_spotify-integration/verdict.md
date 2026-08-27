# Verdict: spotify integration

id: post
status: open
reviewer: deepseek-v4-flash (opencode-go)
date: 2026-08-27

<!-- Produced by @reviewer and/or @security after implementation. -->

## Review

TASK-1: PASS
notes: `MusicServiceEnum::SPOTIFY` added with `baseUrl()` -> `open.spotify.com`
(app/Api/Libraries/Enums/MusicServiceEnum.php); `spotify` section with
`SPOTIFY_CLIENT_ID`/`SPOTIFY_CLIENT_SECRET` via `DockerSecretHelper` in
config/services.php. Secrets stay out of code/.env — consistent with the
discogs pattern. No `.env.example` placeholder exists for discogs either, so
none added is consistent.

TASK-2: PASS
notes: `MusicReleaseDTO` (`id` -> `int|string`, `artistId` ->
`int|string|null`, getters widened) and `MusicSearchResultDTO` (`id` ->
`int|string`). All existing callers pass/consume ints and remain compatible
(verified consumers of `getId()`/`getArtistId()`: GrabMusicReleases
`in_array(..., true)`, the JSON resources, and QuickAddService which never
touches id/artistId).

TASK-3: PASS
notes: New `app/Api/Libraries/Services/External/SpotifyService.php` mirrors
DeezerService: `searchAlbum`, `importFromUrl`, `searchArtists`,
`getNewReleases`, `getAlbums`. OAuth2 client-credentials: Basic-auth POST to
`accounts.spotify.com/api/token` with `grant_type=client_credentials`, Bearer
token cached via `Cache` facade for 3300s (< 3600s token TTL); null tokens are
never cached so unconfigured instances return null (graceful degradation).
Field mapping per spec: string ids, `release_date`+`release_date_precision`
via `parseReleaseDate()` (day/month/year padded deterministically, public for
reuse), cover `images[0]`, album URL `https://open.spotify.com/album/{id}`,
`genres` from full album object with `[]` fallback, `recordType` from
`album_type`. `ConnectionException` handled on both token and data calls.
Search limit 20, artist-albums `include_groups=album,single` limit 50.

TASK-4: PASS
notes: `SpotifyService` injected into `LibraryMusicService` constructor and
`SPOTIFY` match arms added in both `search()` and `import()` — exhaustive
match over all three enum cases (no missing-arm runtime error). Container
auto-resolves the new dependency (no constructor args).

TASK-5: PASS
notes: `search/{service}/{query}` and `import/{service}` enum annotations
widened to `{"deezer","discogs","spotify"}` (descriptions updated);
`MusicReleaseImport.id` -> `oneOf` integer|string. `artist_id` also widened to
`oneOf` integer|string + nullable — a documented, consistent extension beyond
the task text. No new paths added; `tests/Unit/OpenApiSpecTest.php` drift list
unchanged as required.

TASK-6: PASS
notes: `detectBasedOnUrl()` includes `SPOTIFY->baseUrl()` in music detection;
`commitMusic()` now uses a `match(true)` chain with the SPOTIFY branch ordered
before the deezer default. Behavior for discogs/deezer URLs unchanged.

TASK-7: PASS
notes: `getMusicReleases()` still serves Deezer exactly as before (body moved
to `getDeezerMusicReleases()`), behind a clearly marked switch comment —
dormant per decision (a). `getSpotifyMusicReleases()` mirrors the deezer path
(favorites -> `searchArtists` -> `getNewReleases` -> `MusicReleaseDTO`,
provider=spotify) and is public for tests only; not reachable from any route
or job. `GrabMusicReleases` job and its dedup logic untouched; the new
GrabReleasesTest drives a Spotify-style string id through the real job and
asserts store/suppress behavior. Note (non-blocking): the `LibraryMusicRelease`
OpenAPI schema (LibraryMusicReleaseResource, used only by the `releases`
endpoint) still types `id`/`artist_id` as integer — accurate while the live
flow stays Deezer-only; it will need widening when the deferred provider
switch is thrown.

TASK-8: PASS
notes: `SpotifyServiceTest` fakes the token endpoint + search/album/
artist-albums endpoints, asserts token fetched once and cached
(`Http::assertSentCount(3)`), null on 500, null without credentials.
`LibraryApiTest` covers `search/spotify/{query}` and `import/spotify` with
string-id assertions. `QuickAddTest` covers spotify detect and commit (commit
uses `images: []` so no cover download is attempted — matches the
implementation.md note). All new tests mirror existing deezer/discogs test
patterns.

## Security

None run by this reviewer. Static review: no secrets in code or diffs; secrets
flow only through `DockerSecretHelper` (SPOTIFY_CLIENT_ID/SPOTIFY_CLIENT_SECRET
are read at runtime from Docker secrets, same as discogs). Client-credentials
token is app-wide (not per-user), cached under a fixed key in the default cache
store — acceptable. Token never logged.

## Overall

APPROVED

Notes (non-blocking, for the deferred decision):
- `LibraryMusicRelease` OpenAPI schema still documents integer ids; widen when
  the releases provider switch is thrown (TASK-7 follow-up).
- `docs/jobs/post_spotify-integration/tasks.md` contains the full analyst
  breakdown only in the working tree — the committed version on the branch is
  still the scaffold template. Consider committing the filled-in tasks.md so
  the job documentation is complete in git. (Working-tree `.env.testing` and
  `AGENTS.md` are environment mounts, not job changes.)
- Test suite not executed by this reviewer (sandbox restricts to git
  read/commit); verdict is based on full static review of the diff. The
  implementation.md claim of 7 pre-existing unrelated failures (AiService /
  AuthTest) is plausible but unverified here.