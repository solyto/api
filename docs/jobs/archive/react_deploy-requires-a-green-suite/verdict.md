# Verdict: Deploy requires a green suite

id: react
reviewer: @reviewer
date: 2026-10-14

## Review

Branch checked: `feature/react_deploy-requires-a-green-suite`, the same as `brief.md`. Base: `main` (from `.manigot/manigot.json`).

`git diff main...HEAD`: the only non-job-dir change is `Makefile` (+5/-1). Everything else is in the job directory (brief/tasks/implementation/tokens/verdict). Nothing changed outside what `implementation.md` describes.

TASK-1: PASS
notes: `Makefile:1` `deploy:` became `deploy: check`. `Makefile:5-7` adds `check:` with `composer test` and `composer analyse` as separate recipe lines, tab-indented (checked with `cat -A`). The deploy recipe and `audit:` are byte-for-byte unchanged. There is no `.PHONY` and no skip override, as the brief requires.

TASK-2: PASS
notes: I re-ran the checks myself:
- `make -n deploy` prints `composer test`, `composer analyse`, then the `cd deployment && ansible-playbook …` line, in that order.
- Full `make check` (`composer test` + `composer analyse`) exits 0. Pest: 777 tests, 2205 assertions, 0 failures. PHPStan: `[OK] No errors`. The "777 warnings" come from the review sandbox: `.env.testing` is mounted as a char device (`/dev/null`), so `file_get_contents` warns. They are not caused by this diff.
- Negative path: I ran `make deploy` with stub `composer` (exit 1) and stub `ansible-playbook` on `PATH`. It stopped with `make: *** [Makefile:6: check] Error 1`, exit 2. `composer analyse` and the ansible stub were never reached.

The open questions in `tasks.md` (`.PHONY`, command docs, backlog entry) are for the owner to decide and are out of scope for this brief. They are not blockers.

## Security

none (not run). The change only adds a gate before an existing deploy command.

## Overall

APPROVED

No blockers.
