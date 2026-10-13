# Brief: Standards: first (tenant isolation, dev-request attribution, tracked export zip)

type: feature
id: here
branch: feature/here_standards-first-tenant-isolation-dev-request-attribution-tracked-export-zip
date: 2026-10-13
author: Leander Muskalla

From `docs/STANDARDS_BACKLOG.md` → "0. First". Rule numbers refer to
`docs/STANDARDS.md`; counts are from 2026-10-13. Items marked **(approval)**
need the owner's go-ahead first.

- [ ] **Tenant isolation on foreign ids (rule 3.2) — security, do before anything else.**
      Scope every bare `exists:` rule on a user-owned table, or re-scope the
      id in the service. Files: `Todos/Requests/StoreTodoRequest.php:20,22`,
      `UpdateTodoRequest.php:21,23`, `StoreTodoWorkspaceRequest.php:14`,
      `Todos/Controllers/TodoWorkspaceController.php:273,335`,
      `Notes/Requests/StoreNoteRequest.php:14,16`, `UpdateNoteRequest.php:14,16`,
      `StoreNoteCategoryRequest.php:13` (`parent_id`),
      `Libraries/Requests/{Books,Movies,Games,Music}/{Store,Update}*.php`
      (`genres.*`, `tags.*`), `Links/UpdateLibraryLinkRequest.php:18`,
      `Links/*`, `Quotes/*` (`tags.*`),
      `TimeTracking/Requests/{Store,Update}TimeTrackingProjectRequest.php:16`
      (`category_ids.*`). Add one API test per module that posts another
      user's id and expects 422/403. Larger than one commit if done at once;
      one commit per module is fine.
      Check: `grep -rn "exists:" app/Api --include=*.php | grep -v Tests | grep -vc "Rule::exists"` → 0 unscoped (40 today).
- [ ] `DevRequests/Requests/StoreDevRequestRequest.php:19` + `DevRequestService::create:21-27`:
      take `created_by_user_id` from `$request->user()`, drop the rule
      (rule 3.2; pending OQ-8).
- [ ] `tmp/export_0198c253-9ddb-72f0-a823-ee387e5a52f6.zip`: a user-data
      export tracked in git since `072fea9`. Remove from the tree, add
      `tmp/` to `.gitignore`; the owner decides whether history needs
      rewriting (it is public on GitHub).
