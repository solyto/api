# Backlog: remove the committed data export from the git history

created: 2026-10-16

## What

Remove `tmp/export_0198c253-9ddb-72f0-a823-ee387e5a52f6.zip` from the entire
history of solyto/api, on every branch and tag, and have GitHub purge its
cached copies.

## Why

The zip is a real user data export with personal data. It was committed in
`072fea9` (2026-04-22, "Export functionality finished") and deleted in
`d48f7b9` (2026-10-13), but it is still in the history and in tag 1.0.15. The
repo is public, so anyone can download it:
https://github.com/solyto/api/raw/072fea9a87770b6e288fd1dd608e0986b802bc42/tmp/export_0198c253-9ddb-72f0-a823-ee387e5a52f6.zip

## Steps

Done by hand by the owner. Agents can't push or rewrite history.

**Before**

1. Push all local api commits. The rewrite starts from the GitHub state, and
   anything not pushed by then has to be carried over by hand.
2. Check GitHub → api → Forks. A fork keeps its own copy of the history;
   mention any forks to GitHub Support in step 6.
3. Back up: `git clone --mirror git@github.com:solyto/api.git api-backup.git`

**Rewrite**, in a fresh clone, not the working checkout:

4. Remove the file from all history:

   ```sh
   pip install git-filter-repo
   git clone git@github.com:solyto/api.git api-clean && cd api-clean
   git fetch origin '+refs/heads/*:refs/heads/*' --tags   # all branches (planner, authors, …)
   git filter-repo --invert-paths --path-glob 'tmp/export_*.zip'
   git log --all --oneline -- 'tmp/export_*'               # must print nothing
   ```

   Every commit hash from 2026-04-22 onwards changes.

5. Push back:

   ```sh
   git remote add origin git@github.com:solyto/api.git   # filter-repo removes the remote
   git push --force --all
   git push --force --tags
   ```

   Tags and GitHub releases keep their names; the release source zips are
   regenerated from the new tags.

**After**

6. Contact GitHub Support ("remove sensitive data", reference commit
   `072fea9a87770b6e288fd1dd608e0986b802bc42` and the file path). Until they
   purge it, the old commit stays reachable by hash and the link above keeps
   working. Pull requests that reference old commits also keep them.
7. Replace every local api checkout (`/workspace/api` and any other machine)
   with a fresh clone. Pushing from an old checkout brings the file back.
8. Check that the link above returns 404.

## Notes

- Do it before tagging 1.1.0, so the release is tagged on the clean history.
- Old commit hashes quoted in docs (job reports, release notes) no longer
  match afterwards. That's cosmetic.
- F-Droid pins commit SHAs for the mobile repo, not api, so it's unaffected.
  Docker images are built from tags and are unaffected too.
