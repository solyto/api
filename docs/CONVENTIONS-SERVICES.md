# Conventions — Services & Business Logic

Reference files:
`app/Api/Todos/Services/TodoService.php`,
`app/Shared/Services/UserCacheService.php`,
`app/Shared/Services/IntegrationGateway.php`.

## Business logic lives in Services

Controllers validate and delegate; models are data and relations; Services are
where the domain actually happens. This is the single most important structural
rule in the codebase.

- `app/Api/<Module>/Services/` — module-scoped business logic.
- `app/Shared/Services/` — logic shared across modules
  (`UserCacheService`, `IntegrationGateway`, `QuickAddService`,
  `TelegramBotService`, `UrlCrawlerService`).

```php
// Where things happen — app/Api/Todos/Services/TodoService.php
public function update(Todo $todo, array $data): Todo
{
    // date normalisation, completion bookkeeping, recurrence spawning,
    // tag sync, cache invalidation...
}
```

A controller never does the equivalent inline. If you find yourself making two
database calls or running an `if` inside a controller, that is a service method.

### One service per aggregate

Each aggregate (a resource plus its direct dependants) gets one service.
`TodoService` covers todos, subtasks, and recurrence; `TodoCategoryService` and
`TodoWorkspaceService` are separate services for separate aggregates. Do not add
a generic "helper" service for one resource's concerns.

## Method naming

The CRUD core is consistently named `list`, `find`, `create`, `update`,
`destroy` — mirroring the controller actions, minus HTTP concerns:

```php
public function list(User $user): Collection        // TodoService
public function find(Todo $todo): Todo
public function create(User $user, array $data): Todo
public function update(Todo $todo, array $data): Todo
public function destroy(Todo $todo): void
```

Nested or additional operations are descriptive verbs beyond that core:
`addSubtask`, `updateSubtask`, `destroySubtask`, `parse`.

Method signatures follow two patterns:

- read/query methods take a `User` (and query parameters) and return a
  `Collection`;
- write methods take the target model plus the validated `array $data` and
  return the affected model (nothing for deletes).

Write methods that act on a model keep the model as the first parameter — the
controller already resolved and ownership-checked it.

## Functions do one thing

Long public methods are subdivided via private helpers. `TodoService::update`
spawns recurrence through `spawnNextOccurrence()`, and `parse` delegates date
interpretation to `resolveDate()`. Follow that pattern:

```php
// Do — private helper, focus on one sub-operation
private function resolveDate(string $value): string
{
    $relative = match (strtolower($value)) {
        'today'    => now()->toDateString(),
        'tomorrow' => now()->addDay()->toDateString(),
        default    => null,
    };

    if ($relative) {
        return $relative;
    }
    // ... date-format loop
}
```

```php
// Don't — a 100-line public method mixing parsing, DB access and cache writes
// with no helper extraction.
public function update(Todo $todo, array $data): Todo
{
    // date handling, tag handling, completion handling, recurrence handling,
    // cache handling — all inline...
}
```

Keep public surface small; keep helpers `private` unless another class
legitimately needs them.

## DRY

- Recurring housekeeping (loading relations, invalidating caches) happens in
  one place per operation — see `update()` and `create()` both doing the same
  `cache->forget` dance; that repetition is the accepted pattern here because
  the two paths differ in the details. When something is byte-for-byte
  identical across methods, that is the point to extract a private helper.
- Cross-module duplication is solved by moving code to `app/Shared/`, not by
  one module calling another module's service. `IntegrationGateway` is the
  example: Telegram, quick-add and friends all reach todos/calendars through it
  instead of depending on `TodoService` directly.
- Do not introduce repository or gateway abstractions that do not already
  exist. Services talk to Eloquent models directly.

## Caching through UserCacheService

Caches are per-user and go through `App\Shared\Services\UserCacheService`, never
`Cache::` directly. The service provides `remember`, `get`, `has`, `store`,
`replace`, `forget`, `forgetByPrefix` (see `app/Shared/Services/UserCacheService.php`).

The pattern in `TodoService`:

- keys are `[string, user_id]` pairs — cache names are private consts
  (`CACHE_KEY = 'todos'`);
- reads use `remember([$key, $user->id], TTL, fn () => ...)`;
- **every write forgets the affected user's keys explicitly** — `create`,
  `update`, `destroy`, subtask operations, and `spawnNextOccurrence` all call
  `$this->cache->forget([self::CACHE_KEY, ...])` after the change.

```php
// Do — app/Api/Todos/Services/TodoService.php
public function create(User $user, array $data): Todo
{
    $data['user_id'] = $user->id;
    $todo = Todo::create($data);

    if (isset($data['tags'])) {
        $todo->tags()->attach($data['tags']);
    }

    $todo->load(['user', 'category', 'tags', 'subtasks']);

    $this->cache->forget([self::CACHE_KEY, $user->id]);
    $this->cache->forget([self::CACHE_KEY_DUE, $user->id]);

    return $todo;
}
```

```php
// Don't — return the write result without invalidating the read cache.
// Stale lists are a classic bug here.
public function create(User $user, array $data): Todo
{
    return Todo::create($data + ['user_id' => $user->id]);
}
```

Rule: if a method writes to the database, it must end with the `forget` calls
for every cache key the module reads. When a write only affects some keys,
forget exactly those (subtask writes still forget todo lists because subtasks
appear in the todo `list` payload).

## Tenant scoping in queries

All queries inside a service are scoped to one user. Todos, categories, tags:
everything goes through the model's `forUser` scope driven by `$user->id` (or
the model's own `user_id`).

```php
// app/Api/Todos/Services/TodoService.php
public function list(User $user): Collection
{
    return $this->cache->remember(
        [self::CACHE_KEY, $user->id],
        self::CACHE_TTL,
        fn () => Todo::forUser($user->id)
            ->where(...)
            ->with(['category', 'tags', 'subtasks'])
            ->get()
    );
}
```

Never query by primary key alone in a service and assume ownership — the
controller passed an already-checked model for writes, and list-type queries
scope by `forUser` from the start. See
[CONVENTIONS-AUTH-SECURITY.md](./CONVENTIONS-AUTH-SECURITY.md).

## Eager loading

Services eager-load the relations a resource will serialise, so the Resource's
`whenLoaded(...)` calls are satisfied:

```php
$todo->load(['user', 'category', 'tags', 'subtasks']);   // after create/update
Todo::forUser(...)->with(['category', 'tags', 'subtasks'])->get();  // in list
```

If a controller's resource accesses a relation that is not loaded, load it in
the service — this is the anti-N+1 discipline (see
[CONVENTIONS-RESOURCES-OPENAPI.md](./CONVENTIONS-RESOURCES-OPENAPI.md)).

## The cross-module boundary: IntegrationGateway

External entry points (Telegram bot, quick-add, future integrations) do not
reach into module services directly. `App\Shared\Services\IntegrationGateway`
is the seam: it is user-scoped via `setUser()`, guards every call with
`checkAuth()`, and delegates to the owning module's service.

```php
// app/Shared/Services/IntegrationGateway.php
public function todos(): Collection
{
    $this->checkAuth();

    return app(TodoService::class)->list($this->user);
}
```

So: a controller from another module (or a bot) wanting todos calls the gateway,
not `TodoService`. Adding a new entry point means adding a method to
`IntegrationGateway`, not importing module services in yet another location.

## Service checklist

- [ ] Lives in `app/Api/<Module>/Services/` (or `app/Shared/Services/` if shared).
- [ ] One service per aggregate; CRUD named `list`/`find`/`create`/`update`/`destroy`.
- [ ] Reads and writes are scoped to a user (via `User::` or `forUser`).
- [ ] Sub-operations extracted into private helpers.
- [ ] Every write invalidates the affected `UserCacheService` keys.
- [ ] Relations are eager-loaded for the resource.
- [ ] No cross-module dependency except through `app/Shared/` seams like `IntegrationGateway`.