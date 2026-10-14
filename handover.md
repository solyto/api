# Handover: code-quality audit 2026-10-14

Status of `docs/reports/code-quality-audit-2026-10-14.md` as of 2026-10-14.

## State of the tree

Committed on `main` (not pushed):

- `58cc6b3` PHPStan: exclude Pest tests, cap memory, type factories (§2.2)
- `29f09af` Remove empty notes export and Telegram confirm-token stubs (§2.3)

Uncommitted (planned as two commits: code + rules, then backlog move):

- `app/Api/Libraries/Jobs/Grab{Music,Book,Movie}Releases.php`: `$tries = 1`, `$timeout = 300`
- `routes/api.php`: `->name('uploadCover')` removed (rule 1.4)
- `docs/STANDARDS.md`: stale facts fixed, the 14 corrections from
  `standards-audits-2026-10-13.md` applied, rules 1.4 / 3.1 / 9.2 updated,
  OQ-7 closed
- `docs/STANDARDS_BACKLOG.md` deleted; open items moved to 10 entries in
  `docs/backlog/`; references updated in `docs/AGENTS.md`,
  `docs/STANDARDS_WORKFLOW.md`, `docs/TESTING.md`, `docs/STANDARDS.md`

Not ours, leave alone: `.env.testing` (device mount),
`app/Api/Libraries/Tests/GrabReleasesTest.php`, `app/Api/Users/Tests/JobTest.php`.

PHPStan: run only via `composer analyse` (single process, 500M cap). A bare
`phpstan analyse` can OOM-kill the container.

## Done

Blockers are closed (§2.1 earlier, §2.2 and §2.3 on 2026-10-14).

- §3.1: stale facts fixed, backlog moved. The "timeless" rewrite (§5.3) is not done.
- §3.9: owner decision, cache behaviour stays as is.
- In `docs/backlog/`: §3.7 (bot dispatch only), §3.8, §3.11, §3.13 (via
  the inline-validation entry), §3.15 (the OQ-8 part).
- §5.1.1–2: `composer analyse` exists.

## Still open, not tracked anywhere yet

| § | What | Kind |
|---|---|---|
| 3.2 | `LibraryRecommender::favorite()`/`unrated()` crash with a 500 on an empty library | **real bug** |
| 3.7 | `SolytoBot::auth()` calls `exit(1)` mid-request; the trait catches `\Exception` instead of `\Throwable`; dead `commitWithType()` | bug-ish |
| 3.10 | `ScaleCovers` repeats the daily Deezer sweep | waste |
| 3.3 | four jobs use `app()` in `handle()` instead of parameters; unused imports | cleanup |
| 3.5 + 3.6 | the not-found preamble repeated ×11 in `CalendarController` and ×9 in `ContactController`; the `instanceof` chain in `bootstrap/app.php` | refactor (DAV-adjacent) |
| 3.12 | 46 inline-envelope annotations that rule 2.3's check misses | mechanical |
| 3.11 | `else if` → `elseif` in both import loops | tiny |
| 3.14 | document the blind spots of `ResourceSchemaDriftTest` in `TESTING.md` | docs |
| 3.15 | time-tracking `project_id`/`category_id` → scoped `Rule::exists` (optional; already safe) | cleanup |
| 3.16 | unused `expectApiSuccess`/`expectApiError`; the repeated "longterm store" comment; Deezer/Spotify duplicate loop (only once Spotify is switched on) | small |

## Process and decisions

- **§3.4 / §5.2:** decide OQ-6 (Pint). That would also allow a `composer lint` script.
- **§5.1:** write in `docs/AGENTS.md` that a job isn't done until
  `composer test` and `composer analyse` pass, and that reviewers run the
  full suite. CI needs the owner's approval.
- **§5.3:** make `STANDARDS.md` "timeless" by removing the counts from rule bodies.
- **§5.5:** commit hygiene (`chore: commit all`). Partly how manigot merges, so the owner's call.
- **§4:** a list of behaviour changes to check against the frontend.
  Informational, not a task for this repo.

## Suggested next step

The §5.1 gate note in `docs/AGENTS.md` first. It's a few lines, and it's
the report's main process finding, the reason the blockers went unnoticed.
Then put the untracked code items into `docs/backlog/` (or fix §3.2 right
away, since it's a live 500).
