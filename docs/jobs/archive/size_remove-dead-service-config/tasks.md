# Tasks: Remove dead service config

branch: feature/size_remove-dead-service-config (verified: worktree HEAD → `refs/heads/feature/size_remove-dead-service-config`)

## Pre-check result (from brief "Notes")

Searched the whole repo (excluding `vendor/`, plus hidden files explicitly) case-insensitively for
`bremerstadtreinigung`, `trash_calendar_url`, `radicale`, `RADICALE_URL`:

- `config/services.php:34-36` — `bremerstadtreinigung` block (target)
- `config/services.php:52-54` — `radicale` block (target)
- `docs/backlog/remove-dead-service-config.md` and this job's `brief.md` — documentation only, no code
- `.env.example` — **does not exist** in this repo
- `.env.testing` — no matches
- Docker/compose files — none present in this repo (deployment lives in a separate repo, not checked)
- `resources/`, `app/`, `routes/`, `tests/`, `bootstrap/`, `database/` — no matches
- No `config('services.radicale…')` / `config('services.bremerstadtreinigung…')` callers anywhere

→ Nothing outside `config/services.php` references them; the stop condition is not triggered.

Note: `radicale` here is just an unused URL entry in `config/services.php`. It is **not** the `pgsql`
DAV connection, its schema, or the DAV routes, so the hard rule on those does not apply.

---

## TASK-1
**Description:** Delete the `'bremerstadtreinigung' => [ 'trash_calendar_url' => … ],` block and its trailing blank line from `config/services.php` (currently lines 34–37).
**Files:** `config/services.php`
**Dependencies:** none
**Risk:** low — nothing reads the key (verified by repo-wide grep), and it is a static string with no env/secret lookup.

## TASK-2
**Description:** Delete the `'radicale' => [ 'url' => env('RADICALE_URL'), ],` block and its trailing blank line from `config/services.php` (currently lines 52–55).
**Files:** `config/services.php`
**Dependencies:** none (can be done in the same edit as TASK-1; line numbers shift after TASK-1, so match by content, not line number)
**Risk:** low — no code reads `services.radicale`, and `RADICALE_URL` appears in no env file in this repo.

## TASK-3
**Description:** Run `composer test` and `composer analyse` in the worktree and confirm both exit 0.
**Files:** none (verification only)
**Dependencies:** TASK-1, TASK-2
**Risk:** low — config-only removal; the only plausible failure would be a PHP syntax slip (e.g. leftover/missing comma or bracket) in `config/services.php`.

---

## Constraints for the developer
- Touch nothing else in `config/services.php` (all other entries are out of scope per brief).
- Do not touch `AiUsageFeatureEnum::ASSISTANT_CHAT` or anything `ai_usage`-related (out of scope).
- Keep the surrounding formatting (blank line between blocks) consistent with the rest of the file.

## Open points (not guessed, not in scope of the tasks above)
- The brief does not say whether `docs/backlog/remove-dead-service-config.md` should be removed/marked done once this job lands. Left untouched; follow whatever the manigot backlog workflow (`docs/STANDARDS_WORKFLOW.md`) prescribes, or ask the owner.
- A `RADICALE_URL` variable might still exist in the external deployment repo (solyto/deployment) or production `.env`. That is outside `/workspace` and is not changed here; harmless once the config key is gone, but the owner may want to clean it up there separately.
