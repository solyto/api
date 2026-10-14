# How the coding standards get written

This describes the way `docs/STANDARDS.md` is built, so the work can be
resumed in a later session by the owner and an AI agent without
re-explaining anything. It is a working agreement, not a policy. It is the
API-side counterpart of the frontend repository's `docs/STANDARDS_WORKFLOW.md`
and follows it deliberately.

## Goal

One document, `docs/STANDARDS.md`, that states precisely and exhaustively
how code in this repository is written. Every rule in it comes from a
concrete piece of code — either something the owner is unhappy with, or the
owner's own reference module (`app/Api/Todos/`) showing the intended shape.
No rule is imported from a generic Laravel or PSR guide; where a rule rests
on framework behaviour (`env()` under `config:cache`,
`Rule::exists()->where()`, `SerializesModels`) the official Laravel 12
source was read and the rule says so in one line.

The older policy set (`docs/API-CONVENTIONS.md` and eight
`docs/CONVENTIONS-*.md`, written by an AI job on 2026-10-10) was verified
rule by rule against the code on 2026-10-13, its still-valid conventions
folded into section 12 of `STANDARDS.md`, and the files deleted. What was
dropped or corrected is listed under `STANDARDS.md` → Decisions. Where
anything older disagrees with `STANDARDS.md`, `STANDARDS.md` wins.
`docs/TESTING.md` was rewritten the same day to the parts that describe how
to write a test here, with every claim checked against `tests/TestCase.php`
and `phpunit.xml`.

## Roles

**The owner** brings examples: a file, a line range, a commit, a module, or
a thing they remember being annoyed by. One sentence on what bothers them is
enough. Sometimes the question is genuine ("is this actually wrong?"), and
the answer may be "no, this one is fine".

**The agent** does the investigation and the writing:

1. Reads the cited code and the hand-written sibling if one exists (the
   Todos module; an older controller next to a newer one; the version
   before an AI job in `git log -- app/Api/<Module>`).
2. Measures instead of asserting: greps the pattern across `app/`, counts
   occurrences and files, checks `git log`/`git show --stat` against
   `docs/jobs/archive/*/brief.md` to see whether a job wrote it, runs
   `composer test` (and `composer analyse`) when a claim
   depends on it. Numbers go in the answer when they change what the reader
   does.
3. Names the pattern, separates the symptom from the design behind it, and
   says plainly when the owner's own code has the same issue — in this repo
   that is often: the annotation split, the `try/catch` in
   `CalendarController`, the `cache->forget` pairs in `TodoService` are all
   the owner's.
4. Proposes rules a reviewer can apply to a line of code, with a bad/good
   example from this repo (file:line) and a check (a grep, a command, a
   count that must be zero).
5. Writes them into `docs/STANDARDS.md` immediately, restructuring freely.
6. Asks the owner only when a choice is genuinely theirs (two valid
   shapes, a conflict with the reference module, a new abstraction, a new
   package). Everything else is decided and written down.

The agent is not condescending and assumes it knows less about the app than
the owner. It is fair: engineering that is right is said to be right
(`tests/TestCase.php`, `UserCacheService`, `BaseNotification`, the
`SolytoMessage` enum, the service shape as a whole), and the style problems
are listed on top of that.

## What the owner said about how to work (so it need not be repeated)

From the two briefs (`docs/jobs/archive/online_php-laravel-api-best-pratices/brief.md`,
`docs/jobs/dawn_standards/brief.md`):

- "Most of the API I've developed myself in a way that makes sense";
  when AI did things "it's sometimes okay, but sometimes meh". Prefer the
  human path; the Todos module is the reference.
- The older best-practice documents "were written by a worse quality AI
  agent and are to be taken with a grain of salt" — verify, don't trust.
  (Done; they are gone.)
- Replicate the frontend's set: `STANDARDS.md`, `STANDARDS_BACKLOG.md`,
  `STANDARDS_WORKFLOW.md`, a trimmed `TESTING.md`, plus a report on
  testing/formatting/linting tooling.
- Hard rules from `docs/AGENTS.md` apply to the standards work itself: no
  package installs without asking, never touch `app/Dav/` or the `pgsql`
  connection without flagging it, never print `.env`/secret values, no new
  abstractions. Tooling (Pint config, Larastan, CI) is therefore a backlog
  item marked "(approval)", never an action.
- Sessions are isolated; the agent's memory does not survive. Everything
  that matters for the next session lives in this file, in
  `docs/STANDARDS.md`, and in `docs/backlog/`.

## Shape of `docs/STANDARDS.md`

- **The recurring themes** — the six ways this codebase goes wrong, so a
  reader who remembers nothing else remembers those.
- **Rules**, numbered by section (1 Controllers and routing, 2 OpenAPI,
  3 Requests and validation, 4 Services, 5 Models and queries, 6 Resources,
  7 Errors and responses, 8 Caching, 9 Jobs/commands/schedule, 10 Comments,
  11 Formatting, 12 Architecture conventions). Each rule has: a one-line
  rule, why it matters, a bad/good example from this repo with file and
  line, and a check where one exists.
- **Decisions**: choices the owner made that are not derivable from the
  code (no inline `validate()`, no `strict_types`, `$casts` property,
  deletes return 200, what was dropped from the old docs).
- **Open questions** (OQ-n): things waiting for the owner's answer, each
  with the data point that raised it. The owner's own inconsistencies with
  a rule are recorded here, not silently dropped and not silently turned
  into exceptions.

Concrete cleanups go to `docs/backlog/` as manigot backlog entries (one
idea per file, written with the `backlog-entry` skill, naming its rule and
a check line), never into `STANDARDS.md`. `docs/STANDARDS_BACKLOG.md` was
retired on 2026-10-14; its open items moved there. The working notes with full counts from the first session
are in `docs/jobs/dawn_standards/findings-*.md`, `provenance.md` and
`tooling-report.md`; they are job artefacts and may go stale.

## Resuming

Start a session in this repository and say something like:

> Let's continue the coding standards work. Read `docs/STANDARDS_WORKFLOW.md`
> and `docs/STANDARDS.md`, then I'll give you the next example.

Or, to clear the open questions first:

> Read `docs/STANDARDS.md` → Open questions. Here are my answers: OQ-1 …

Then throw examples as before. When the examples start landing on rules
that already exist, it is time to stop collecting and run the backlog.

## State at the end of the first session (2026-10-13)

Covered: controllers and routing, the health route, OpenAPI annotations
(prefix, envelope, 422 schema, operationIds, drift), FormRequests and the
`exists:` tenant-isolation finding, services (constructor users, service
locator, string dispatch, cache-forget repetition, pass-throughs, return
types, the Libraries ×4 copy, export services, logging, catching), models
and queries, resources and `whenLoaded`, errors and the central exception
map, caching, jobs/commands/schedule/notifications/bots, comments,
formatting and Pint, the folded architecture conventions, and the test
suite's shape. DAV was described, not ruled on.

Not done, in order:

1. The eight open questions in `STANDARDS.md` need the owner's answers;
   OQ-1 (annotation path prefix) and OQ-6 (Pint) each unblock a large
   mechanical backlog item.
2. `STANDARDS_BACKLOG.md` → "0. First": the `exists:` scoping across
   Todos/Notes/Libraries/TimeTracking, the dev-request attribution, and
   the tracked export zip in `tmp/`.
3. The tooling decisions (Pint config, Larastan, CI, Dependabot npm) — all
   need approval; none was installed.
4. Keep collecting examples whenever something new annoys the owner; most
   will now land on an existing rule, which is fine.
