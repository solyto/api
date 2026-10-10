# Conventions — Controllers & Routing

Reference files:
`app/Api/Todos/Controllers/TodoController.php`, `routes/api.php`,
`app/Api/HandlesApiAuth.php`.

## The controller's job

A controller is an HTTP adapter. It does exactly four things, in order:

1. Receive a `FormRequest` (validation already done) or, for trivial reads, a
   plain `Request`.
2. Check ownership/authorisation.
3. Delegate the real work to the module's Service.
4. Wrap the result in `ApiResponse`.

It does **not** query the database, apply business rules, or transform data.
Those belong in the Service and the Resource.

```php
// app/Api/Todos/Controllers/TodoController.php
public function store(StoreTodoRequest $request): JsonResponse
{
    $todo = $this->todoService->create(
        $request->user(),
        $this->todoService->parse($request->user(), $request->validated())
    );

    return ApiResponse::success(new TodoResource($todo), 'Todo created successfully.', 201);
}
```

### Thin-controller rule

If a controller method needs a conditional, a loop, or two coordinated writes,
that logic belongs in the Service. The controller should be short enough to read
in one screen. Compare `TodoController::show()` (three lines) with
`TodoService::update()` (the real work) — that is the intended split.

### Constructor property promotion of services

Inject dependencies through the constructor, promoted to `private readonly`.
Do not resolve services with `app()` inside a controller method.

```php
// Do — app/Api/Todos/Controllers/TodoController.php
public function __construct(private readonly TodoService $todoService) {}
```

```php
// Don't
public function store(...)
{
    $service = app(TodoService::class); // hidden dependency
}
```

The controller declares the `HandlesApiAuth` trait alongside the promoted
service:

```php
class TodoController
{
    use HandlesApiAuth;

    public function __construct(private readonly TodoService $todoService) {}
}
```

## Ownership checks

Route-model binding finds the model for anyone. Ownership is a separate,
explicit check at the top of `show`, `update`, `destroy`, and any nested
action. Use `HandlesApiAuth::isResourceOwner()` and `abort_unless(..., 403)`.

```php
// Do — app/Api/Todos/Controllers/TodoController.php
public function show(Request $request, Todo $todo): JsonResponse
{
    abort_unless($this->isResourceOwner($request, $todo), 403);

    $todo = $this->todoService->find($todo);

    return ApiResponse::success(new TodoResource($todo), 'Todo retrieved successfully.');
}
```

```php
// Don't — trusting route binding alone lets any authenticated user read
// anyone's todo.
public function show(Todo $todo): JsonResponse
{
    return ApiResponse::success(new TodoResource($todo));
}
```

Nested resources check both the parent and that the child belongs to it:

```php
// app/Api/Todos/Controllers/TodoController.php
public function updateSubtask(Request $request, Todo $todo, TodoSubtask $subtask)
{
    abort_unless($this->isResourceOwner($request, $todo), 403);
    abort_unless($subtask->todo_id === $todo->id, 403);
    // ...
}
```

`HandlesApiAuth` also exposes `isAdmin()` and `isSuperAdmin()` for endpoints
that legitimately need privilege (do not roll your own role check).

## Validation in the controller

Prefer a `FormRequest` per write endpoint. It keeps the rules next to the
`@OA` request body and keeps the controller thin.

For genuinely trivial nested actions (one field, no reuse), inline
`$request->validate()` is accepted in this codebase — `TodoController::addSubtask`
does it:

```php
// Allowed for a trivial endpoint — app/Api/Todos/Controllers/TodoController.php
$validatedData = $request->validate(['title' => 'required|string']);
$subtask = $this->todoService->addSubtask($todo, $validatedData['title']);
```

The rule of thumb: if the payload has more than a field or two, or the same
shape is validated elsewhere, extract a `FormRequest`. See
[CONVENTIONS-REQUESTS-RESPONSES.md](./CONVENTIONS-REQUESTS-RESPONSES.md).

## Routing

All routes live in `routes/api.php`, split into two `Route::prefix('v1')`
groups: the public group (health + `auth/*`) and the `auth:sanctum` group for
everything else. Do not create route files elsewhere.

```php
// routes/api.php
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // ...
});
```

Conventions inside the group:

- **Group by domain prefix.** Wrap related routes in
  `Route::prefix('<domain>')->group(...)` (e.g. `todos`, `notes`,
  `libraries`). Use `->name('<domain>.')` when route names matter.
- **`apiResource` for full CRUD.** `Route::apiResource('tags', TagController::class)`
  gives the five REST actions and binds `{tag}`. For a resource served at the
  domain root, the codebase uses `Route::apiResource('/', XController::class)->parameters(['' => 'todo'])`
  (see the `todos` group).
- **Sub-resources get explicit routes** next to the `apiResource`, e.g.
  `Route::post('{todo}/subtasks', [TodoController::class, 'addSubtask'])`.
- **Non-CRUD actions are named descriptive verbs**, not `create`/`update`:
  `listDueDate`, `updateCalendarsOrder`, `attachCategories`, `listWidgetEvents`.
- **Route parameters bind to models by name.** `{todo}` binds `Todo $todo`,
  `{feedSubscription}` binds `$feedSubscription`. Keep the parameter name equal
  to the model's camelCase name so implicit binding works without `->parameters()`.
- **Throttle public auth routes only**, via named limiters:
  `->middleware('throttle:auth-login')` (see the public group).

## Naming

| Thing | Convention | Example |
| --- | --- | --- |
| Controller | `<Resource>Controller` | `TodoController` |
| REST methods | `index` `show` `store` `update` `destroy` | `TodoController::store` |
| Custom actions | descriptive verb phrase | `listDueDate`, `addSubtask` |
| Service method | `list` `find` `create` `update` `destroy` | `TodoService::update` (see Services doc) |
| `@OA` `operationId` | `<resource><Action>` camelCase | `todoIndex`, `todoStore`, `todoAddSubtask` |
| `@OA` `tags` | plural resource name | `Todos` |

The `operationId` is what the generated client uses, so it must be unique and
stable. Follow the existing `<resource><Action>` pattern rather than reusing a
generic name.

## Controller checklist

- [ ] `use HandlesApiAuth;` and constructor-promoted service(s).
- [ ] `show`/`update`/`destroy` start with an ownership `abort_unless`.
- [ ] Payload comes from a `FormRequest` (or a justified inline `validate`).
- [ ] Work delegated to a Service.
- [ ] Result wrapped in `ApiResponse::success(...)` with the right status.
- [ ] `@OA` docblock present (see [CONVENTIONS-RESOURCES-OPENAPI.md](./CONVENTIONS-RESOURCES-OPENAPI.md)).
- [ ] Route registered in `routes/api.php` inside the `auth:sanctum` group.
