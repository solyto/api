# Backlog: TmdbService broad exception catch

created: 2026-10-14

## What
`Libraries/Services/External/TmdbService.php:235` wraps TV-show parsing in `catch (\Exception) { return null; }`. Decide: drop the try, catch the specific exception, or keep it with a comment saying why (rule 4.8).

## Why
A blanket catch turns any bug in the parsing code into a silent "not found". The other external services were moved to `ConnectionException` in `ca1627b`; this one was left as open question Q1 of that job.

## Out of scope
The other TMDB methods and services.

## Notes
The try covers array access on the API response, not an HTTP call, so `ConnectionException` is not the right type here.
