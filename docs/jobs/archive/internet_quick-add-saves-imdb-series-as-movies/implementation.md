# Implementation: Quick Add saves IMDb series as movies

id: internet
developer: developer
date: 2026-11-14

## Summary

Quick Add now saves IMDb series as `category: series`. The IMDb import (via TMDb) reports series as `'tv'`, which the old `=== 'series'` check never matched. Tests cover both the series and the movie path.

## Changes

TASK-1: `app/Shared/Services/QuickAddService.php` — `commitMovie` maps `in_array($dto->getType(), ['tv', 'series'], true)` to `series`, otherwise `movie`.
TASK-2: `app/Api/Dashboard/Tests/QuickAddTest.php` — two `QuickAddService::commit` tests with faked TMDb `/find` + `/tv` / `/movie` responses and `Http::preventStrayRequests()` (no `poster_path`, so no cover download): `tt0944947` → `category === 'series'`, `tt1160419` → `category === 'movie'`.
TASK-3: `composer test` exit 0 (1529 tests, warnings only), `composer analyse` exit 0.

## Known issues / follow-ups

- Open from the brief: `POST /libraries/movies/import/imdb` now returns `type: 'tv'` instead of `'tvSeries'`. Nothing in this repo reads `'tvSeries'`; a human has to confirm that the separate frontend repo doesn't depend on it.
- Test environment in this worktree: `vendor/`, `.env`, and the gitignored `storage/` tree were missing. I ran `composer install` and created `storage/framework/*`, `storage/logs`, and `storage/app/public/user` (all ignored). Without `storage/app/public/user`, two `ExportTest` cases fail. All 1529 warnings are `file_get_contents(/workspace/.env)` from phpdotenv because `.env` is missing.
