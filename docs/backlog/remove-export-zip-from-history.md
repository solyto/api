# Backlog: remove the committed data export from the git history

created: 2026-10-16
updated: 2026-11-17 (scope extended: tags, release archives, Docker Hub)

> **MANUAL, NOT FOR AGENTS.** History rewrite, force push, GitHub Support and
> Docker Hub. Done by the owner by hand. An automated agent must skip this
> entry.

## What

Remove `tmp/export_0198c253-9ddb-72f0-a823-ee387e5a52f6.zip` everywhere it is
published:

1. the git history of solyto/api (every branch and tag), plus GitHub's cached
   copies;
2. the GitHub release source archives of the affected versions;
3. the `solyto/api-php` Docker Hub images of the affected versions.

## Why

The zip is a data export of the owner's own account (personal data). It was
committed in `072fea9` (2026-04-22, "Export functionality finished") and
deleted in `d48f7b9` (2026-10-13). The repo is public, so it can still be
downloaded:
https://github.com/solyto/api/raw/072fea9a87770b6e288fd1dd608e0986b802bc42/tmp/export_0198c253-9ddb-72f0-a823-ee387e5a52f6.zip

Where it is (checked 2026-11-17):
- **In the file tree** of tags 1.0.7 – 1.0.15 and 1.0.78. So the GitHub
  release archives ("Source code (zip/tar.gz)") of these versions contain it.
- **In the history only** of tags 1.1.0 – 1.1.3 (and every later commit).
- **Docker Hub:** `dockerhub/Makefile` builds `solyto/api-php` from a shallow
  clone of the tag (`fetch-api-src`), and `dockerhub/api/api-php.Dockerfile:37`
  copies the whole source (`COPY src/ /var/www/`) without a `.dockerignore`.
  So `solyto/api-php` images for 1.0.7 – 1.0.15 (if they were pushed) contain
  the file at `/var/www/tmp/export_….zip`. Images from 1.1.0 on don't.

## Steps

**Before**

1. Push all local api commits. Anything not pushed before the rewrite has to
   be carried over by hand.
2. Check GitHub → api → Forks. A fork keeps its own copy of the history;
   mention any forks to GitHub Support in step 7.
3. Back up: `git clone --mirror git@github.com:solyto/api.git api-backup.git`

**Rewrite**, in a fresh clone, not the working checkout:

4. Remove the file from all history:

   ```sh
   pip install git-filter-repo
   git clone git@github.com:solyto/api.git api-clean && cd api-clean
   git fetch origin '+refs/heads/*:refs/heads/*' --tags   # all branches and tags
   git filter-repo --invert-paths --path-glob 'tmp/export_*.zip'
   git log --all --oneline -- 'tmp/export_*'               # must print nothing
   ```

   Every commit hash from 2026-04-22 onwards changes, including the commits
   the tags 1.0.7 – 1.1.3 (and later) point to.

5. Push back:

   ```sh
   git remote add origin git@github.com:solyto/api.git   # filter-repo removes the remote
   git push --force --all
   git push --force --tags
   ```

**Release archives and Docker Hub**

6. GitHub regenerates the release source archives from the rewritten tags.
   Download the "Source code (zip)" of e.g. 1.0.15 afterwards and check that
   `tmp/` has no export zip.
7. Docker Hub: delete the `solyto/api-php` tags 1.0.7 – 1.0.15 (and 1.0.78 if
   it exists), or rebuild and re-push them from the cleaned tags
   (`make push-api-php VERSION=…` in `dockerhub/`). Check with
   `docker run --rm solyto/api-php:1.0.15 ls /var/www/tmp` (no export zip).
8. Optional, so it can't happen again: a `dockerhub/api/.dockerignore` with
   `tmp/`, `.git`, `tests` and other non-runtime folders.

**After**

9. Contact GitHub Support ("remove sensitive data", reference commit
   `072fea9a87770b6e288fd1dd608e0986b802bc42` and the file path). Until they
   purge it, the old commit stays reachable by hash and the link above keeps
   working. Pull requests that reference old commits also keep them.
10. Replace every local api checkout (`/workspace/api`, the hotfix branch
    `hotfix/1.1.3.1`, any other machine) with a fresh clone. Pushing from an
    old checkout brings the file back.
11. Check that the link above returns 404.

## Notes

- Old commit hashes quoted in docs (job reports, release notes, the hotfix
  docs) no longer match afterwards. That's cosmetic.
- F-Droid pins commit SHAs for the mobile repo, not api, so it's unaffected.
- `tmp/` is gitignored today (`.gitignore:46`), so new exports aren't
  committed again.
