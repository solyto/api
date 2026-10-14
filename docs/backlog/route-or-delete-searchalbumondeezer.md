# Backlog: Route or delete searchAlbumOnDeezer

created: 2026-10-14

## What
`LibraryMusicController::searchAlbumOnDeezer()` is annotated as `GET /libraries/music/search/deezer/{artist}/{album}` but has no route. Either route it or delete the method and its annotation, then remove its entry from `OPENAPI_KNOWN_DRIFT` (`tests/Unit/OpenApiSpecTest.php`) and delete the constant, which is then empty (rule 2.2).

## Why
The spec documents an endpoint that returns 404. It is the last known OpenAPI drift.

## Out of scope
Other Deezer features.

## Notes
Owner decision (kept on purpose, 2026-10-13; see `STANDARDS.md` 2.2). The two empty stubs with the same problem were deleted on 2026-10-14 (`29f09af`).
