# Summary

Added Spotify as a third music provider for the music library, mirroring the
existing Deezer/Discogs integration:

- a `SpotifyService` (new, no precedent in the repo) that implements the
  OAuth2 client-credentials flow (token fetch + cached reuse) and maps Spotify
  album/artist objects into the shared `MusicReleaseDTO` /
  `MusicSearchResultDTO`, including precision-aware release-date parsing and
  string IDs;
- `spotify` wired into the generic search/import endpoints, the QuickAdd
  detect/commit flow, and the OpenAPI annotations;
- a **dormant** Spotify releases collection path in `LibraryReleases` — fully
  built and tested, but not wired into live behavior: `getMusicReleases()`,
  the cached `releases` endpoint and the daily `GrabMusicReleases` job keep
  serving Deezer exactly as before, behind a well-marked switch.

## Changes

TASK-1: Added `MusicServiceEnum::SPOTIFY` (`spotify`, `baseUrl()` ->
`open.spotify.com`) and a `spotify` section (`SPOTIFY_CLIENT_ID` /
`SPOTIFY_CLIENT_SECRET` via `DockerSecretHelper`) in `config/services.php`.
Secrets stay in Docker secrets, never `.env`/code.

TASK-2: Widened `id`/`artistId` on `MusicReleaseDTO` and `MusicSearchResultDTO`
from `int` to `int|string` (+ getter return types) so Spotify's 22-char string
IDs fit. Existing int callers (deezer/discogs/releases) are unaffected.

TASK-3: New `app/Api/Libraries/Services/External/SpotifyService.php` mirroring
`DeezerService`: `searchAlbum`, `importFromUrl`, `searchArtists`,
`getNewReleases`, `getAlbums`. Token is fetched once from
`accounts.spotify.com/api/token` (Basic auth, `grant_type=client_credentials`)
and cached via the `Cache` facade for 55 min (token TTL 3600s); null tokens are
never cached so unconfigured/failing instances degrade gracefully (service
returns null → empty results). Field mapping: string ids, `release_date` +
`release_date_precision` (day/month/year padded deterministically, exposed as
`parseReleaseDate()` for reuse), cover via `images[0]`, album URL
`https://open.spotify.com/album/{id}`, `genres` from the full album object with
`[]` fallback, `recordType` from `album_type`.

TASK-4: Injected `SpotifyService` into `LibraryMusicService` and added `SPOTIFY`
match arms in `search()`/`import()` (deezer-style).

TASK-5: OpenAPI: `search/{service}/{query}` and `import/{service}` enums now
`{"deezer", "discogs", "spotify"}` (descriptions updated). `MusicReleaseImport`
schema `id` (and `artist_id`, same widening) changed from `type="integer"` to
`oneOf` integer|string. `tests/Unit/OpenApiSpecTest.php` drift list unchanged
(no new paths).

TASK-6: `QuickAddService`: `detectBasedOnUrl()` now detects
`open.spotify.com` URLs as music; `commitMusic()` routes Spotify URLs to
`import(MusicServiceEnum::SPOTIFY, ...)` via a `match(true)` chain ordered
before the deezer default branch.

TASK-7: `LibraryReleases` gained `getSpotifyMusicReleases()` (favorites →
`searchArtists` → `getNewReleases` → `MusicReleaseDTO`, provider=spotify) and
the existing deezer body was moved to `getDeezerMusicReleases()`;
`getMusicReleases()` still serves Deezer, with a comment-marked switch for the
deferred replace/join/toggle decision. Tests added for the Spotify path
(LibraryServiceTest) and for the job's dedup tolerating string IDs
(GrabReleasesTest — drives a string id through the real `GrabMusicReleases`
job and asserts the notified-set stores/suppresses it).

TASK-8: New `SpotifyServiceTest` (token + search + album + artist-albums
endpoints faked, token cached and fetched once, null on failure, null without
credentials), endpoint tests for `search/spotify/{query}` and
`import/spotify` in `LibraryApiTest`, and QuickAdd detect/commit tests for
Spotify URLs in `QuickAddTest` (commit test uses `images: []` so no cover
download is attempted — `Http::fake([...])` does not prevent stray requests).

## Known issues / follow-ups

- Full-suite run shows 7 pre-existing failures unrelated to this job: 4
  `AiService` tests (no `AI_API_KEY` in the sandbox → `trim(null)`) and 3
  `AuthTest` password-reset tests (no `APP_KEY`/`.env` in the sandbox). Both
  fail on the base branch too; all music/dashboard/OpenAPI tests added or
  touched here pass.
- Frontend "is Spotify configured?" signal is out of scope (tracked as a
  follow-up per tasks.md decision 5); unconfigured instances degrade
  gracefully.
- The releases provider decision (replace Deezer / run both / toggle) is
  deferred; the switch in `LibraryReleases::getMusicReleases()` is marked.
- `MusicReleaseImport` `artist_id` was widened to `oneOf` integer|string along
  with `id` — the task text only named `id`, but both fields carry the same
  widened type.