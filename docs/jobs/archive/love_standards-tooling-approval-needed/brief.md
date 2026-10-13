# Brief: Standards: tooling (approval needed)

type: feature
id: love
branch: feature/love_standards-tooling-approval-needed
date: 2026-10-13
author: Leander Muskalla

From `docs/STANDARDS_BACKLOG.md` → "Tooling (approval needed for each)". Rule numbers refer to
`docs/STANDARDS.md`; counts are from 2026-10-13. Items marked **(approval)**
need the owner's go-ahead first.

- [ ] **(approval)** `pint.json` with `"preset": "laravel"` + `composer lint`
      script, then one repo-wide `./vendor/bin/pint` commit (OQ-6, rule 11.1).
      No new package. REJECTED.
- [ ] **(approval)** `composer require --dev larastan/larastan`, level 5,
      `phpstan.neon` with `paths: [app]` and `app/Dav` excluded at first
      (tooling report §3). New package. APPROVED.
- [ ] **(approval)** `.github/workflows/tests.yml`: PHP 8.4, `composer install`,
      `php artisan test` on push/PR; no Docker, no coverage gate to start
      (the `4f7deeb`/`703d82a` history). Infrastructure, no package. REJECTED.
- [ ] **(approval)** `.github/dependabot.yml`: add `package-ecosystem: "npm"`. REJECTED.
- [ ] **(approval, later)** coverage driver (`pcov`) in the CI image only,
      `--min` once a baseline exists. REJECTED
- [ ] A small script (`scripts/` or a Pest test) that compares each
      resource's `@OA\Schema` properties with its `toArray()` keys, so rule
      2.6 has a check. The throwaway version used for this job is described
      in `docs/jobs/dawn_standards/findings-data.md` §3. APPROVED
