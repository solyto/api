# Verdict: deactivate movie release notifications

id: elect
reviewer: reviewer
date: 2026-10-08

<!-- Produced by @reviewer and/or @security after implementation. -->

## Review

Reviewed `git diff main...HEAD` (base branch `main`, per `.manigot/manigot.json`).
The only production change on the branch is `routes/console.php`; everything else
is the job's own docs (`brief.md`, `tasks.md`, `implementation.md`, `tokens.json`,
`verdict.md`).

TASK-1: PASS
notes: `routes/console.php:21-24` comments out
`Schedule::job(\App\Api\Libraries\Jobs\GrabMovieReleases::class)->weekly();`
and adds a three-line explanatory comment referencing job `elect` /
`deactivate-movie-release-notifications`. The two sibling entries
(`GrabMusicReleases` at line 19, `GrabBookReleases` at line 20) and all other
schedule entries are untouched. A repo-wide search confirms the schedule entry
was the only live trigger: the sole remaining references to
`GrabMovieReleases` are its own class, its test (`GrabReleasesTest.php`, which
calls `handle()` directly), and the job docs — so the job class, its
notification, `LibraryReleases`, the DTO, the per-user settings, and the tests
are all preserved as required ("keep the rest the same").

TASK-2: PASS
notes: Verification-only, no files changed. The change is a comment-out of the
only dispatcher; the existing movie tests invoke `handle()` directly
(`app/Api/Libraries/Tests/GrabReleasesTest.php:167,179`) and therefore still
exercise the job. The developer's reported results (focused test 0 failures,
full suite 0 failures, `pint` pass, scheduler enumeration no longer listing
`GrabMovieReleases` while music/book remain) are consistent with the diff. I
could not re-run the suite in this session (restricted to git read/commit), but
the change is a pure schedule registration removal with no code-path impact, so
there is no correctness risk to re-verify.

## Security

None. Comment-only change to a schedule registration; no secrets, no data
access, no auth surface touched.

## Overall

APPROVED

The single-line schedule disable is the smallest reversible change that satisfies
"deactivate the job, keep the rest the same for now". Nothing needs to change
before merge.

Note (non-blocking, environment only): `git status` shows uncommitted working-tree
modifications to `AGENTS.md`, `.env.testing`, and `tokens.json`. These are not
part of the branch's committed diff and are tooling/mount artifacts, not
developer output; they must stay uncommitted.
