# Conventions — Requests & Responses

Reference files:
`app/Api/Todos/Requests/StoreTodoRequest.php`,
`app/Api/Todos/Requests/UpdateTodoRequest.php`,
`app/Api/ApiResponse.php`,
`bootstrap/app.php`.

## FormRequests per write endpoint

Every create/update/action-with-a-payload gets a `FormRequest` in
`app/Api/<Module>/Requests/`, named `<Verb><Resource>Request`:

- `StoreTodoRequest` → `POST /v1/todos`
- `UpdateTodoRequest` → `PUT /v1/todos/{todo}`
- `StoreTodoCategoryRequest` → the categories sub-resource

Rules use Laravel pipe strings, not arrays — that is the house style:

```php
// app/Api/Todos/Requests/StoreTodoRequest.php
public function rules(): array
{
    return [
        'title' => 'required|string|max:255',
        'description' => 'nullable|string|max:1000',
        'priority' => 'sometimes|in:low,medium,high',
        'category_id' => 'nullable|exists:todo_categories,id',
        'tags' => 'nullable|array',
        'tags.*' => 'exists:tags,id',
    ];
}
```

Update requests use `sometimes` so partial updates work:

```php
// app/Api/Todos/Requests/UpdateTodoRequest.php
'title' => 'sometimes|string|max:255',
'is_completed' => 'sometimes|boolean',
```

### `attributes()` for readable messages

Field names that would read badly in a validation message are overridden via
`attributes()` so the user sees "due date" instead of "due_at":

```php
public function attributes(): array
{
    return ['due_at' => 'due date'];
}
```

### No `authorize()` in the FormRequest

This codebase does not put ownership checks in FormRequests. Requests validate;
controllers authorise with `HandlesApiAuth::isResourceOwner()` + `abort_unless`
(see [CONVENTIONS-CONTROLLERS.md](./CONVENTIONS-CONTROLLERS.md)). Do not move
ownership into a request's `authorize()` — it would split the rule from the
controller's existing pattern.

### Keep rules and OpenAPI in sync

The `@OA\RequestBody` on the controller method copies the same constraints
(required fields, enums, maxLength). If you change a rule, change the
annotation in the same commit (see
[CONVENTIONS-RESOURCES-OPENAPI.md](./CONVENTIONS-RESOURCES-OPENAPI.md)).

A validation failure returns `422` with the standard error envelope —
`App\Api\ApiResponse::validationError()` via the central exception handler in
`bootstrap/app.php`, wired automatically because a `ValidationException` is
thrown.

## The response envelope

`App\Api\ApiResponse` (see `app/Api/ApiResponse.php`) is the **only** way a
controller returns JSON (the health endpoint is the sanctioned exception).

Success:

```json
{
  "success": true,
  "message": "Todo created successfully.",
  "data": { ... },
  "timestamp": "2026-10-10T12:00:00+00:00"
}
```

Error:

```json
{
  "success": false,
  "message": "Todo not found.",
  "timestamp": "...",
  "errors": { "field": ["message"] }
}
```

Convenience shorthands exist: `ApiResponse::error(...)`,
`validationError(...)`, `unauthorized(...)`, `forbidden(...)`, `notFound(...)`,
`serverError(...)`. `data` may be null, a resource, a resource collection, or a
paginator.

### Pagination meta

When `data` is a `LengthAwarePaginator`, `ApiResponse` merges a `meta.pagination`
block automatically — you do not build it:

```json
{
  "success": true,
  "message": "...",
  "data": [ ... ],
  "meta": {
    "pagination": {
      "current_page": 1, "last_page": 3, "per_page": 20, "total": 54,
      "from": 1, "to": 20, "has_more_pages": true
    }
  }
}
```

Pass paginators to `ApiResponse::success(...)`; do not manually call
`->paginate()` and then shape the JSON yourself.

## Status codes

| Situation | Code |
| --- | --- |
| Created a resource | `201` |
| Read / update / delete succeeded | `200` |
| Validation failed | `422` |
| Not authenticated | `401` |
| Authenticated but not the owner | `403` |
| Route/model not found | `404` |
| Too many requests | `429` |

```php
// Do — app/Api/Todos/Controllers/TodoController.php
return ApiResponse::success(new TodoResource($todo), 'Todo created successfully.', 201);

return ApiResponse::success(null, 'Todo deleted successfully.'); // 200
```

Destroys return `200` with `data: null`, not `204` — the frontend expects the
envelope. Match the existing pattern.

## Centralised exception handling — no ad-hoc try/catch

`bootstrap/app.php` maps exceptions to the envelope in one place:

- `ValidationException` → 422
- `AuthorizationException` → 403
- `AuthenticationException` → 401
- `ModelNotFoundException` → 404 (with the model name)
- `NotFoundHttpException` → 404
- `MethodNotAllowedHttpException` → 405
- `QueryException` → 500
- `ThrottleRequestsException` → 429
- other `HttpException` → its status

Controllers and services therefore **do not** wrap logic in try/catch to build
error responses. Throw the Laravel exception (or use `abort_unless`/`abort`)
and let the handler render it:

```php
// Do — ownership failure as a plain abort, mapped centrally to 403
abort_unless($this->isResourceOwner($request, $todo), 403);
```

```php
// Don't
try {
    $todo = Todo::findOrFail($id);
} catch (ModelNotFoundException $e) {
    return ApiResponse::notFound('...');
}
```

Reserve manual `ApiResponse::error(...)` calls for cases the central handler
cannot know about (e.g. a domain-level failure inside a service that should not
leak as a 500). The default is: throw, don't catch.

## Checklist

- [ ] Write endpoint has a `FormRequest` (rules + `attributes()`), rules mirrored in `@OA`.
- [ ] Response built with `ApiResponse::success/error/...`, never raw `response()->json`.
- [ ] `201` only for creates; `200` otherwise (including deletes with `data: null`).
- [ ] Paginators passed to `ApiResponse` directly.
- [ ] No ad-hoc try/catch for exceptions the central handler already maps.