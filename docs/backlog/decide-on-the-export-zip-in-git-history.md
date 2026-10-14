# Backlog: Decide on the export zip in git history

created: 2026-10-14

## What
Decide whether to rewrite git history to remove `tmp/export_0198c253-9ddb-72f0-a823-ee387e5a52f6.zip`, a user-data export that was tracked from `072fea9` until `d48f7b9` removed it and ignored `tmp/`.

## Why
The repository is public on GitHub, so the export is still downloadable from history.

## Out of scope
The tree itself; the file is already gone and `tmp/` is in `.gitignore`.

## Notes
Owner decision. A rewrite (e.g. `git filter-repo`) changes every later commit hash and needs a force-push and re-clones; the alternative is accepting the exposure. If the data belongs to a real user, that may matter more than the repo cost.
