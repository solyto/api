# Handover: code-quality audit 2026-10-14

Status of `docs/reports/code-quality-audit-2026-10-14.md` as of 2026-10-14
(second session).

## State of the tree

Everything is committed on `main` (not pushed). Only `.env.testing` shows as
modified: it is a device mount, not ours, leave it alone. A bare `git diff`
fails on it (`unsupported file type`); pass paths (`git diff -- app docs`).

Commits from the first session: `58cc6b3` (§2.2), `29f09af` (§2.3),
`68ded42` (job timeouts, route name, STANDARDS fixes, backlog move).

Commits from this session:

| Commit | § | What |
|---|---|---|
| `674e46f` | 5.1 | `docs/AGENTS.md`: `composer test` + `composer analyse` are the done gate; `composer analyse` listed under Commands |
| `e51fd79` | 3.2 | `LibraryRecommender::favorite()`/`unrated()` return null → documented 404 instead of 500; two regression tests |
| `b528513` | 3.10 | `ScaleCovers` no longer sweeps music releases (`GrabMusicReleases` does it at 05:00) |
| `ae861e6` | 3.3 | the four calendar/contact jobs take their service as a `handle()` parameter; unused imports gone |
| `1e737cb` | 3.14 | `TESTING.md`: `ResourceSchemaDriftTest` blind spots |
| `a45d8cd` | 3.16 | `TESTING.md`: `expectApiError` is used, only `expectApiSuccess` has no callers |
| `d970fed` | 3.5, 3.12 | backlog entries (see below) |

Gate at the end of this session: `composer test` 755 passed, `composer
analyse` no errors.

## Environment

- `vendor/` was stale (2026-08-22, before Larastan): `phpstan` was missing.
  Fixed with `composer install` from the lock (owner approved; no
  `composer.json`/`composer.lock` change). Agent sessions do not get a full
  `vendor/` sync (it is gitignored); the owner is looking at that on the
  manigot side.
- PHPStan only via `composer analyse` (single process, 500M cap). A bare
  `phpstan analyse` can OOM-kill the container.

## Backlog (new this session)

- `docs/backlog/calendar-and-contact-controller-not-found-preamble.md` (§3.5)
- `docs/backlog/inline-envelope-annotations-message-variant.md` (§3.12)

## Dropped on purpose (owner decision, no backlog entry)

- §3.4 / §5.2 / OQ-6: Pint is rejected (it was already rejected in job
  `love_standards-tooling-approval-needed`). OQ-6 is closed in
  `STANDARDS.md` Decisions, rule 11.1 is now "match the file you are in",
  and `laravel/pint` is removed from `require-dev`.
- §5.1 CI: rejected in the same job (`.github/workflows/tests.yml`);
  already recorded in `STANDARDS.md` Decisions ("CI … not approved").
- §5.5 commit hygiene (`chore: commit all`): ignored by owner decision.
- §3.2 leftovers: `toRecommendation()` helper and the typed exception in
  `LibraryRecommender::assertType()`.
- §3.7 and anything in `app/Bots/`: "the bot works", too risky for quick
  changes. Known and deliberately left: `phpunit.xml` does not include
  `app/Bots/Tests` or `app/Dav/Tests`, so `composer test` does not run them
  (DAV's 13 pass when run directly; running `SolytoBotTest.php` directly
  dies silently on `exit(1)` in `SolytoBot::auth()`, and one test fails on
  `chat_id` string vs `'integer'` cast). Do not "fix" this without the owner.
- §3.6 (`instanceof` chain: the report itself says two is fine), §3.11
  (two `else if`, not worth a commit), §3.15 (optional, already
  safe), §3.16's "longterm" comment and Spotify/Deezer loop.

## Still open: owner decisions

- **§5.3:** make `STANDARDS.md` "timeless" (drop counts from rule bodies;
  `TESTING.md`'s helper table counts too).
- **§4:** behaviour changes to check against the frontend. Add: recommend
  `favorite`/`unrated` now answer 404 instead of 500 when nothing matches.

## Suggested next step

Nothing in the audit is left to do here without an owner decision. Next is
one of the decisions above, or a job from `docs/backlog/`.
