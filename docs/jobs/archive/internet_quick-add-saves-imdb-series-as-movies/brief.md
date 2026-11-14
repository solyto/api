# Brief: Quick Add saves IMDb series as movies

type: feature
id: internet
branch: feature/internet_quick-add-saves-imdb-series-as-movies
date: 2026-11-14
author: Leander Muskalla

## What

`QuickAddService` (`app/Shared/Services/QuickAddService.php:206`) sets
`'category' => $dto->getType() === 'series' ? 'series' : 'movie'`. The IMDb
import (`TmdbService::buildDetailDTO`) returns `'tv'` for series. Before the
IMDb fix (e249b72) it returned imdbapi's `'tvSeries'`. Neither value has
ever matched, so every series added through Quick Add is saved as a movie.

The review of the IMDb fix raised this as a follow-up, but it was never done.

## Steps

1. Change the check to `in_array($dto->getType(), ['tv', 'series'], true)`.
   Keep `'series'` in case another source uses it.
2. Add a Quick Add test with an IMDb series id (faked TMDb response) that
   asserts `category === 'series'`.

## Notes

- Clients of `POST /libraries/movies/import/imdb` now get `type: 'tv'`
  instead of `'tvSeries'`. The app doesn't seem to read it; check that before
  closing this.
