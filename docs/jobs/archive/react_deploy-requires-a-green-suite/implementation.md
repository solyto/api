# Implementation: Deploy requires a green suite

id: react
developer: @developer
date: 2026-10-14

## Summary

`make deploy` now depends on a new `check` target, which runs `composer test` and then `composer analyse`. If either one fails, `make` stops before the `ansible-playbook` recipe runs. The playbook and `audit:` are unchanged.

## Changes

TASK-1: `Makefile`: `deploy:` became `deploy: check`. Added `check:` with two recipe lines (tab-indented): `composer test` and `composer analyse`. The deploy recipe and `audit:` are exactly as before. No `.PHONY` and no skip override, per the brief.

TASK-2: Verification. Nothing was deployed and nothing extra was committed.
- `make -n deploy` prints, in order: `composer test`, `composer analyse`, then `cd deployment && ansible-playbook -i hosts.ini deploy.yml`.
- `make check` on the clean tree exits 0: Pest `Tests: 777 warnings (2205 assertions)`, no failures; PHPStan `[OK] No errors`. This also meets the job's `composer test` / `composer analyse` exit-0 requirement.
- Negative case: I temporarily changed `toBe(200)` to `toBe(299)` in `tests/Unit/ApiResponseTest.php:31` and ran `make check`. It exited 2 (`Tests: 1 failed`, `make: *** [Makefile:6: check] Error 1`) with no `composer analyse` output. Then I reverted the change; `git status` was clean.
- `deploy` recipe skipped: I ran `make deploy` with a stub `composer` on `PATH` that always exits 1, so the playbook could not be reached. Output: `composer test` / `fake composer test` / `make: *** [Makefile:6: check] Error 1`, exit 2. The `ansible-playbook` line never ran.

## Known issues / follow-ups

- Environment setup for the worktree (not committed, all gitignored): `vendor/` was missing, so I ran `composer install` from the existing lock file (no new packages). `storage/framework/{views,cache/data,sessions,testing}` and `storage/app/public/user` were also missing. Without them, `config:clear` fails ("Please provide a valid cache path") and two `ExportTest` cases fail (`fopen(.../storage/app/public/user/...)`). On a fresh checkout, `make check` therefore needs those runtime directories to exist. The development checkout normally has them.
- The open questions from `tasks.md` are still open: `.PHONY: deploy check audit` hardening, documenting `make check` in `.claude/CLAUDE.md` / `docs/AGENTS.md`, and what happens to the backlog entry.
- `.env.testing` appears as modified in `git status`. In this session it is a character-device mount from the sandbox, not a real file change, so I left it out of the commits on purpose.
