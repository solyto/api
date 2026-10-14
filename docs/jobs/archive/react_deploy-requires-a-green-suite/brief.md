# Brief: Deploy requires a green suite

type: feature
id: react
branch: feature/react_deploy-requires-a-green-suite
date: 2026-10-14
author: Leander Muskalla

## What
`make deploy` runs `composer test` and `composer analyse` first, and stops if either fails. The `Makefile` today has `deploy:` (the `ansible-playbook` call in `deployment/`) and `audit:`. Add a `check:` target that runs both commands, and make `deploy` depend on it (`deploy: check`). Nothing else changes, and the playbook itself is not touched.

## Why
There is no CI: `.github/workflows/tests.yml` was removed in `703d82a`, and CI was rejected by the owner (`STANDARDS.md` Decisions). Without a gate, the client contract tests (`client-contract-tests-1-0-15`) and the migration guard (`migration-compatibility-guard`) only protect old apps if someone remembers to run them. With the gate, a break that old apps would see stops the deploy deterministically, with no agent involved.

## Out of scope
- CI.
- The `deployment` repository and the playbook.
- A "skip the checks" override. If one is wanted, it is the owner's call; `cd deployment && ansible-playbook …` stays possible by hand.

## Notes
`composer test` needs `vendor/` with dev dependencies in sync with `composer.lock` on the machine that deploys. That is already the case if it is the development checkout.

Check: in a scratch branch, break a test on purpose; `make deploy` must stop before `ansible-playbook` runs. Run this with `make -n deploy` or a dry run, so nothing is actually deployed.
