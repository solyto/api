# Tasks: Deploy requires a green suite

id: react
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

## Context (what was read)

- Branch check: worktree HEAD is `refs/heads/feature/react_deploy-requires-a-green-suite`, the same as `branch:` in `brief.md`.
- `Makefile` (whole file, 7 lines): has two targets, `deploy:` (`cd deployment && ansible-playbook -i hosts.ini deploy.yml`) and `audit:` (`composer audit`). It has no `.PHONY` and no variables. Recipes are indented with tabs.
- `composer.json` scripts: `test` = `config:clear` + `php artisan test`. `analyse` = `phpstan analyse --debug --memory-limit=500M --no-progress`. Both commands already exist, so no new tooling is needed.
- `docs/STANDARDS.md` 13.7 already describes the gate ("`make deploy` runs `composer test` and `composer analyse` first"). This job makes the code match the documented rule, so `STANDARDS.md` needs no edit.
- The repo root has no files named `check`, `deploy` or `audit`, so leaving out `.PHONY` does not break the targets today.

## Task breakdown

TASK-1: Add a `check:` target to the `Makefile` that runs `composer test` and then `composer analyse`, and change `deploy:` to `deploy: check`. Leave the `deploy` recipe and `audit:` exactly as they are.
  files: `Makefile`
  depends: none
  risk: low. This is a two-line change to a 7-line file. The only ways it can go wrong are (a) using spaces instead of a tab in the recipe, which breaks `make` immediately and visibly, or (b) touching the `ansible-playbook` line, which is out of scope.
  notes:
  - Keep one command per recipe line (no `&&` chain needed). `make` stops at the first command that returns non-zero, so a red `composer test` never reaches `composer analyse`, and a failed `check` never reaches the `deploy` recipe.
  - Do not add a skip/override variable (out of scope per brief).
  - Do not add `.PHONY`. See open question 1.

TASK-2: Verify the gate without deploying anything, and record the evidence in `implementation.md`.
  files: none committed (only `implementation.md` in the job dir)
  depends: TASK-1
  risk: low. The one real danger is running `make deploy` for real, because the `ansible-playbook` recipe would run if `check` passes. Never run `make deploy` without `-n`.
  steps:
  1. `make -n deploy` should print `composer test`, then `composer analyse`, then the `cd deployment && ansible-playbook …` line, in that order. This shows that `check` runs first.
  2. `make check` on the clean tree should exit 0. This also covers the job's own "`composer test` and `composer analyse` exit 0" requirement.
  3. Temporarily break one assertion in any Pest test (do not commit it), run `make check`, and confirm a non-zero exit with no `composer analyse` output after the failure. Then revert the change and confirm `git status` is clean. A non-zero exit from `check` is what stops `make` before the `deploy` recipe. `make -n` alone cannot show this, because a dry run executes nothing.

## Open questions / unclear scope (not guessed; not part of the tasks above)

1. **`.PHONY`.** The brief says "nothing else changes", so it is not in TASK-1. Without it, a file named `check` in the repo root would silently skip the gate. That is unlikely, but it would defeat the purpose. Adding `.PHONY: deploy check audit` is a one-line hardening step, and it is the owner's call.
2. **Command docs.** `/workspace/.claude/CLAUDE.md` and `docs/AGENTS.md` list `make deploy` under Commands but do not mention the new `make check` or that deploy now runs the suite. They were not updated, because the brief does not ask for it.
3. **Backlog entry.** `docs/backlog/deploy-requires-green-suite.md` is the source of this brief. Whether it gets removed or marked done when the job lands depends on the manigot workflow, not on this task list.
