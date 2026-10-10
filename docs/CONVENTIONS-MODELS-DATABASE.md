# Conventions — Models & Database

Reference files:
`app/Api/Todos/Models/Todo.php`, `app/Api/Todos/Factories/TodoFactory.php`,
`database/migrations/`, `config/database.php`.

## Model conventions

Models are data + relations + scopes — no business logic. The reference is
`app/Api/Todos/Models/Todo.php`:

```php
class Todo extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): TodoFactory
    {
        return TodoFactory::new();
    }

    protected $fillable = [
        'title', 'description', 'user_id', 'category_id', // ...
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'due_at' => 'date',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo { ... }
    public function category(): BelongsTo { ... }
    public function tags(): BelongsToMany { ... }
    public function subtasks(): HasMany { ... }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
```

Rules:

- **`$fillable`, never `$guarded`.** Explicit allow-lists protect against mass
  assignment; `user_id` is filled by the service (`$data['user_id'] = $user->id`
  in `TodoService::create`), never taken from the request.
- **`$casts`, not accessors.** Booleans and dates are cast; do not write
  `is_completed` accessor methods when a cast does it.
- **Typed relations with return types** (`BelongsTo`, `BelongsToMany`,
  `HasMany`, ...) and explicit FK where non-standard (`parent()` →
  `'parent_task_id'`).
- **`HasUuids` + `HasFactory`** are the two traits on app models. UUIDs are
  handled by the trait; factories are co-located.
- **`scopeForUser` (or a `forUser` static) is the tenant scope.** Every
  owner-scoped model exposes one, and services use it for all list queries.
  `Todo::forUser($user->id)` / `TodoCategory::forUser($user->id)` —
  see [CONVENTIONS-SERVICES.md](./CONVENTIONS-SERVICES.md).
- **No business logic in the model.** If a model method would compute,
  coordinate, or write to a second table, it belongs in the Service. Recurrence
  spawning, cache invalidation, date resolution — all in `TodoService`.

## Co-located factories

Factories live in `app/Api/<Module>/Factories/` next to the model, and the
model wires them via `newFactory()` (not the `Factory::factoryForModel()`
global registration). See `app/Api/Todos/Factories/TodoFactory.php`:

- default `definition()` plus named **states** (`pending()`, `completed()`,
  `overdue()`, `dueToday()`, `noDueDate()`);
- a `forUser(User $user)` state so tests never scatter `user_id` literals:

```php
public function forUser(User $user): static
{
    return $this->state(fn (array $attributes) => [
        'user_id' => $user->id,
    ]);
}
```

Factories use `App\Api\Users\Models\User::factory()` as the default foreign key
instead of `User::factory()->create()->id` implicitly — the relationship is
declared by model-class reference in `definition()` (`'user_id' => User::factory()`).

## Migrations are the source of truth

Schema for the app connection lives in `database/migrations/` — one file per
change, named `YYYY_MM_DD_HHMMSS_description.php`, Laravel's anonymous-class
style with `up()`/`down()` (see
`database/migrations/2026_05_20_000001_add_link_to_todos.php`).

- Add a new migration for schema changes; **never edit an applied migration**
  (deployed environments have already run it).
- Keep migrations additive where possible (`->after('description')` on
  `link`, nullable — see the linked migration).
- The `down()` reverses the change; it does not need to restore data.
- Show migrations before running them — the project rule is that migrations are
  never executed without review first.

## The two connections — and the DAV hard rule

This repository has two database worlds:

| Connection | Default | Owned by | Contains |
| --- | --- | --- | --- |
| `database.default` | MariaDB | the app | all feature-module data (`todos`, `users`, ...) |
| `pgsql` | PostgreSQL | SabreDAV | the CalDAV/CardDAV tables, contact photos |

**Hard rule: never change the `pgsql` connection, its schema, or the DAV routes.**
The SabreDAV backends (`app/Dav/`) hard-code `'pgsql'` and depend on that exact
layout. The DAV migrations themselves are driver-aware
(`database/migrations/2025_12_08_161442_create_dav_tables.php` keeps the
original PostgreSQL DDL and emits an equivalent SQLite schema for tests), and in
tests `tests/TestCase.php` remaps `pgsql` to a SQLite file. Touch that boundary
and everything DAV breaks — never do it silently.

Practical consequences:

- App feature migrations use `Schema::` on the default connection only.
- Multi-connection work (e.g. contact photo writes, DAV reads) goes through the
  services that already own that split
  (`app/Dav/Services/DavService`, contact photo services) — do not open raw
  `DB::connection('pgsql')` from a new controller.
- If a task genuinely requires touching DAV, flag it loudly before any change.

## Enums and helpers

Shared enums live in `app/Shared/Enums/`, shared exceptions in
`app/Shared/Exceptions/`. Module-specific value objects follow the same
pattern inside the module (`app/Api/Dashboard/Enums/QuickAddContentType`,
`app/Api/Dashboard/DTOs/DetectionResult`) — default to the module-folder
convention, move to `app/Shared/` only when a second module needs them.

## Checklist

- [ ] Model: `HasFactory, HasUuids`, `$fillable`, `$casts`, typed relations, `scopeForUser`.
- [ ] No business logic in the model.
- [ ] Co-located factory wired via `newFactory()`, with `forUser()` where owner-scoped.
- [ ] Schema change = new additive migration, shown before it is run.
- [ ] `pgsql`/DAV left untouched; any need to touch it is flagged first.