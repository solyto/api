# Verdict: file dav report

id: innocent
reviewer: reviewer
date: 2026-11-13

## Review

Diff `main...HEAD` touches only files in `docs/jobs/innocent_file-dav-report/`
(brief, tasks, implementation, report, verdict, tokens). No code, routes,
config, migrations or dependencies changed, which matches the report-only scope.

`composer test`: exit 0 (5091 assertions, warnings only, no failures).
`composer analyse`: exit 0, no errors.

TASK-1: PASS
notes: Inventory in report §2 is accurate. Spot-checked: `DavServerFactory.php`
node tree/plugins/base URI, `LaravelAuthBackend.php:11-23` (main password,
no throttle), `config/filesystems.php:56-61` (`dav_data` unused), `routes/web.php:10-11`,
`public/dav.php:22-38` (`$response->send()` commented out). The developer
disagrees with the task's premise that `DavController` buffers the body. That
deviation holds up: `CorePlugin.php:85-93` turns GET bodies into streams, and
Symfony `Response::setContent(?string)` (`Response.php:426`) rejects a resource.
It is documented clearly in implementation.md and report §2.3.

TASK-2: PASS
notes: The sabre/dav version (4.7.1) comes from `composer.lock` and vendor.
`HomeCollection` email naming and `0777` mode (`HomeCollection.php:86-92`), the default
`{DAV:}authenticated`→`{DAV:}all` ACL for non-IACL nodes (`DAVACL/Plugin.php:299-305`,
`:566-573`), the host-disk `getQuotaInfo` and the FSExt ETag all match the vendor source.
The unused `locks`/`propertystorage` tables exist in the DAV migration.

TASK-3: PASS
notes: The two tiers are kept separate, and the tier 2 APIs are labelled
documented, partly documented, implementation-defined or undocumented.
Every requirement is sourced. iOS/Android is honestly marked as unverified.

TASK-4: PASS
notes: S-1/S-2/S-3 storage options, MariaDB vs `pgsql` metadata per rule 12.6,
ETags, quotas (streaming enforcement because of chunked Finder uploads), export
and deletion. All `pgsql` and DAV-tree touches are marked [DAV-FLAG].

TASK-5: PASS
notes: R-1–R-9 are framed as risks with mitigations, not as guarantees.
Operations covers limits, FPM capacity, queue, backups and self-hosted vs solyto.app.

TASK-6: PASS
notes: Outline-level REST surface that cites STANDARDS.md rules, the ApiResponse
envelope, OpenAPI and locales. The frontend-repo dependency is stated. No new abstractions.

TASK-7: PASS
notes: Options A/B/C1/C2 compared, with rough estimates and their assumptions.
New services and dependencies are marked as needing approval. Phased plan 0–6.

TASK-8: PASS
notes: report.md has every required section (summary, current state, client tiers,
architecture, storage, security, operations, API/frontend, phased plan, open
decisions, sources). implementation.md points to it.

## Security

None. The job only adds documentation, and the report contains no secrets.

## Overall

APPROVED

No blockers.
