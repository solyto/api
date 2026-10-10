# Conventions — Auth & Security

Reference files:
`app/Api/HandlesApiAuth.php`, `app/Api/Users/Controllers/AuthController.php`,
`app/Shared/Helpers/DockerSecretHelper.php`, `routes/api.php`,
`bootstrap/app.php`.

## Authentication: Sanctum on every non-public route

Bearer tokens via `laravel/sanctum`. The only public routes are the health
check and the `auth/*` endpoints; everything else is inside

```php
// routes/api.php
Route::prefix('v1')->middleware('auth:sanctum')->group(function () { ... });
```

- **New endpoints go inside that group.** Never add a route to the public
  group without a very good reason (login/register are the examples).
- Public `auth/*` routes are additionally rate-limited via named limiters
  (`throttle:auth-login`, `throttle:auth-register`, `throttle:auth-passkey`).
- Token lifecycle lives in `AuthService` (`createToken`, logout/revoke; the
  User model uses `HasApiTokens`). Passkeys are registered/authenticated
  through `PasskeyController` — do not reinvent token creation.

Controllers receive `$request->user()` from the guard; access to the
authenticated user never comes from parsing the token manually.

## Tenant isolation: every resource is owned

All feature data is per-user. Ownership lives in `App\Api\HandlesApiAuth`:

```php
// app/Api/HandlesApiAuth.php
public function isResourceOwner(Request $request, $resource): bool
{
    return $resource->user_id === $request->user()->id;
}

public function isAdmin(Request $request): bool
{
    return $request->user()->isAdmin() || $request->user()->isSuperAdmin();
}

public function isSuperAdmin(Request $request): bool
{
    return $request->user()->isSuperAdmin();
}
```

Rules:

1. **Every `show`, `update`, `destroy` (and nested action) starts with**
   `abort_unless($this->isResourceOwner($request, $model), 403);` — see
   `TodoController::show/update/destroy`.
2. **Never trust route-model binding alone for ownership.** Binding resolves
   any model for any authenticated user; it is not an authorisation check.
3. **List/collection queries scope by user in the service** (`forUser`) so a
   non-owner never even enters the response stream
   (`TodoService::list` → `Todo::forUser($user->id)`).
4. **Nested children are checked against their parent too**
   (`abort_unless($subtask->todo_id === $todo->id, 403)`).
5. **Privileged actions** (admin/scoped features) use `isAdmin()` /
   `isSuperAdmin()` from the same trait.

Forbidden (and not-found, unauthenticated, validation) responses are produced
by the central exception handler in `bootstrap/app.php`; an `abort_unless`
failure renders as the standard `403` envelope automatically.

## Validation is the first line of defense

Mass assignment is prevented twice: `$fillable` allow-lists on every model
(never `$guarded`) and FormRequest validation on every write endpoint
(`required`/`sometimes`, enums via `in:`, existence via `exists:...`). Routing
`$request->validated()` into services keeps raw user input out of model
`create()`. Details in
[CONVENTIONS-REQUESTS-RESPONSES.md](./CONVENTIONS-REQUESTS-RESPONSES.md) and
[CONVENTIONS-MODELS-DATABASE.md](./CONVENTIONS-MODELS-DATABASE.md).

## Secrets: `DockerSecretHelper` only

Third-party API keys (Hardcover, TMDB, Discogs, Mailgun, imgproxy, AI, bot
tokens, the app key, ...) are runtime secrets, not configuration:

```php
// app/Shared/Helpers/DockerSecretHelper.php
public static function get(string $identifier): ?string
{
    if (getenv('APP_DEBUG') === "true") {
        return getenv($identifier);
    }

    $filePath = getenv($identifier . '_FILE');

    if (!$filePath) {
        return null;
    }

    return trim(file_get_contents($filePath));
}
```

Hard rules:

- **Read secrets only through `DockerSecretHelper::get(...)`.** Never `env(...)`
  for a third-party key, never a literal in code, never a config
  `env('KEY')` shortcut for secrets.
- **Never log secrets.** Cache failures and errors must not include values;
  `UserCacheService` logs keys but not payloads — keep that discipline when
  adding logging.
- **Never commit secrets.** `.env`/`.env.testing` and anything produced by
  `DockerSecretHelper` stay out of code, logs, migrations, and diffs.
- Tests set a fixed non-secret test key and mock the AI client
  (`tests/TestCase.php`) — follow that pattern rather than inventing keys.
- `composer audit` is part of the workflow (`composer.json` scripts); keep
  dependencies current to stay ahead of disclosed vulnerabilities.

## CORS / CSRF context

CSRF tokens are bypassed only for `webhooks/*` (`bootstrap/app.php`), and the
DAV/telegram webhooks are token-authenticated by their own mechanisms. Do not
widen the CSRF exemption; new webhook-style routes should authenticate via a
secret through `DockerSecretHelper`, like the Telegram bot token.

## Checklist

- [ ] Route inside the `auth:sanctum` group (or deliberately public + rate-limited).
- [ ] `show`/`update`/`destroy`/nested actions start with an ownership `abort_unless`.
- [ ] Collection queries scoped with `forUser` in the service.
- [ ] New third-party key access goes through `DockerSecretHelper`; nothing logged/committed.
- [ ] No `$guarded`, no unvalidated `$request->all()` into models.