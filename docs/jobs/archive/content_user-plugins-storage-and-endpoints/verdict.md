# Verdict: User plugins storage and endpoints

id: content
reviewer: @reviewer
date: 2026-10-14

## Review

Branch: `feature/content_user-plugins-storage-and-endpoints` (matches brief). Base: `main`.
Reviewed `git diff main...HEAD`. Every changed file appears in tasks.md/implementation.md. No changes are out of scope, and nothing unrelated was refactored. `.env.testing` (sandbox `/dev/null` mask) is modified in the working tree but is **not** committed.

Verification (run by the reviewer in the worktree):
- `composer test`: exit 0. The 859 warnings are all `file_get_contents(.env.testing)` from the sandbox mask, and there are no failures. All 18 `App\Plugins\Tests\UserPluginApiTest` tests ran.
- `composer analyse`: exit 0 ("No errors"). The baseline is untouched.
- `vendor/bin/pest --list-tests`: lists all 18 UserPluginApiTest tests.

TASK-1: PASS
notes: `app/Plugins/Enums/PluginEnum.php` has the single case `AI = 'ai'`.

TASK-2: PASS
notes: `AiProviderEnum::label()` is a `match` in the same shape as `baseUrl()`.

TASK-3: PASS
notes:
- The migration is additive and auto-increment `id` is justified per 5.4.
- Columns match the brief: `foreignUuid` with cascade, `enabled` default false, nullable `credentials`/`settings`, unique (`user_id`, `plugin`).
- The migration was not run against a real database. The owner must still review it before `php artisan migrate`, as the developer noted.

TASK-4: PASS
notes:
- Model: `$fillable`, `$casts` property (5.3), typed `user()`, `scopeForUser(Builder, string)`, `newFactory()`.
- Factory: `forUser` state.
- The `plugin` → `PluginEnum` cast is a small addition that tasks.md flagged. It is fine.

TASK-5: PASS
notes:
- `api_key` is required only when no readable key is stored.
- `provider` uses `Rule::enum`. `model` uses `Rule::in` against the snapshot for the payload provider.
- No `authorize()`, and the request has no side effects.
- `provider`/`model`/`enabled` are required, per the analyst's default for unclear point 1.

TASK-6: PASS
notes:
- Every read of `credentials` goes through `hasReadableCredentials()`, which catches `DecryptException` by type (4.8).
- The dirty-check workaround in `update()` is covered by the different-key test and has a why-comment.
- `model_available` dispatches through a `match` on `PluginEnum` (4.3).
- Every query starts with `forUser`.

TASK-7: PASS
notes:
- Routes are in `routes/api.php`: `users/me/plugins` inside the `users` prefix, and a separate `plugins` block for `ai/providers`, both in the sanctum group.
- Implicit enum binding gives the 404 envelope, and a test covers it.
- `@OA`: relative paths, `allOf` SuccessResponse, 404 and 422 → `ValidationErrorResponse`, unique `<resource><Action>` operationIds.
- The request body matches the rules. The `UserPlugin` schema lists `has_api_key` and never `api_key`.

TASK-8: PASS
notes: `app/Plugins` was added to `config/l5-swagger.php` and `OpenApiSpecTest`. The drift glob covers `app/Plugins/Resources` and `app/Plugins/*/Resources`. Both spec tests pass.

TASK-9: PASS
notes: The brief requires encrypted storage, no key in responses, provider/model validation, `model_available` false, cross-user isolation, cascade delete, and a different-key decrypt. All of these are covered, plus 404, 401 and the providers shape. `phpunit.xml` now includes `app/Plugins/Tests`.

TASK-10: PASS
notes: The reviewer confirmed that both commands exit 0 (see above).

## Security

Not run separately. From the correctness review: credentials are never serialized, because the resources take a computed state array. Every query is user-scoped. The key is encrypted at rest.

## Overall

APPROVED

There are no blockers. Before deploy, the owner still has to review and run the migration (CLAUDE.md hard rule). That is a deploy step, not a code change.
