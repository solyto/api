# Verdict: CalDAV outbound requests — redirects, IPv6 ranges, weak ETags

id: elderly
reviewer: reviewer
date: 2026-11-14

## Review

Reviewed `git diff main...HEAD` on `feature/elderly_caldav-outbound-requests-redirects-ipv6-ranges-weak-etags`.
Every changed file is accounted for in `implementation.md`. The only rename
(`docs/backlog/caldav-outbound-hardening.md` → job `brief.md`) is the job
scaffold converting the backlog entry.

Verification in the worktree:
- `composer test`: exit 0, 1541 tests, 5471 assertions, no failures. Every
  test reports the same warning,
  `file_get_contents(/workspace/.env): No such file or directory`. That
  comes from this container (no `.env`, and `.env.testing` is mounted as a
  device), not from the branch.
- `composer analyse`: exit 0, no errors.
- `RemoteCalDavClientLiveTest` run 5 times in a row: stable each time.
  `dav.test` does not resolve here (`getent` rc=2), so a successful request
  can only come from the `CURLOPT_RESOLVE` pin.

TASK-1: PASS
notes: `RemoteCalDavClient::sendFollowing()` resolves `Location` into `$next`
and throws `unavailable(..., 'insecure redirect')` before the next hop goes
out when the current hop is https and the next is not. Same-scheme and
cross-host https redirects still go through. The test checks that discovery
returns `[]` and that no `http://` request is sent.

TASK-2: PASS
notes: The four ranges are added to `BLOCKED_RANGES`. `::ffff:0:0:0/96` is
the SIIT translated prefix, as the analyst decided. `::ffff:0:a00:1`
matches neither the mapped nor the compatible unwrap in `isPublic()`, so it
reaches the range check. `inRange()` handles `/10` with its remainder mask.
There is one dataset case per range, and the public-address tests still
pass.

TASK-3: PASS
notes: `ifMatchHeader()` strips `W/` from the single tag and from each list
item. `normalizeEtag()` and the stored ETags are unchanged. The tests cover:
- PUT and DELETE through the proxy against a weak-ETag remote
  (`FakeCalDavServer::$weakEtags`).
- A stale weak tag still gets 412 for PUT and DELETE, and the remote data
  is untouched.
- A list is sent as strong tags.

TASK-4: PASS
notes: `send()` now goes through `sendFollowing()`, so every hop is checked
by the guard and the TASK-1 downgrade check applies. When a collection is
unavailable or answers non-2xx, `CalendarImport::getEvents()` logs host and
status only, with no credentials or path. The `DavImportTest` cases cover a
301 that is followed and a 500 that is logged while the other calendar
still imports.

TASK-5: PASS
notes: The test uses a real `php -S` listener (not the fallback). It covers
pinning, a `Content-Length` over the cap and a chunked response over the
cap. It found a real gap: an exception thrown from `progress` escaped
without the failure log. `request()` now catches
`ExternalCalendarUnavailableException` as well and logs `too large` through
the `$tooLarge` flag. That is in scope under the task's risk note.
`implementation.md` explains why the router lives in `tests/Fixtures/http/`.

TASK-6: PASS
notes: `docs/TESTING.md` now has the fixture dir, the loopback-listener rule,
the DAV file list, coverage-map rows and a caveat. The backlog entry was
consumed into the job brief.

## Security

No new findings. The HTTPS downgrade is closed and the guard now blocks the
four ranges. Logs carry the host and status only. `ContactImport` still
uses the unguarded `Http` client. It is out of scope and is recorded as a
follow-up in `implementation.md`.

## Overall

APPROVED

No blockers.
