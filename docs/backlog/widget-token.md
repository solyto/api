# Backlog: widget token bound to its session, with a central scope gate

created: 2026-10-29

## What

Add a separate, restricted token for Android home-screen widgets. The
widget's native code uses it to read widget data and run widget actions from
outside the app. It is issued by the app with its normal token, bound to that
token (it dies with it), and valid only on routes named `widgets.*`.

This is job 2 of 6 in `solyto/mobile`
`docs/reports/android-homescreen-widgets-decisions.md` (D1). Read that
document first. This job adds no `widgets.*` routes yet; job 3 does.

## Why

Interactive widgets need server access while the app is closed. Reusing the
app's token would put the main credential in a second place and make the two
copies fight over refreshes. A restricted token limits what a leak can do.

Binding it to the session makes revocation impossible to forget: logout,
revoking a session in the session list, `logout-all`, a password change,
account deletion and a token refresh all end widget access too, without
extra code paths. A lost phone is handled by revoking that phone's session.

## Steps

1. **Migration:** add a nullable `parent_token_id` to
   `personal_access_tokens`, a foreign key to `personal_access_tokens.id`
   with `cascadeOnDelete()`, and a **unique** index on it. `down()` drops
   both.
2. **Central gate** (in a service provider's `boot()`):
   `Sanctum::authenticateAccessTokensUsing(...)`. A token **without** the
   `*` ability is valid only when the current route's name matches
   `widgets.*`; everything else keeps Sanctum's own `$isValid`. This is the
   only place the scope is enforced, so routes added later anywhere are closed
   to widget tokens by default. Confirm in a test that the route is resolved
   when the callback runs.
3. **Issuing:** `POST /v1/auth/widget-token` in the authenticated `auth`
   group. The route name must **not** start with `widgets.`. It:
   - requires the current token to be a `PersonalAccessToken` (not a
     `TransientToken` from cookie auth); otherwise an error with a code;
   - in one transaction, deletes the existing widget token of the current
     token (if any) and creates a new token named `widget-token` with
     abilities `['widget']`, `parent_token_id` = the current token's id and
     `expires_at` = the current token's `expires_at`;
   - returns `{ token, token_type, token_expires_at }`, like login does, with
     status 201.
   The unique index is the guarantee against two concurrent requests creating
   two widget tokens; handle the losing request cleanly (no 500).
4. **Rate limit:** a `widget-token` limiter (per user, for example 10 per
   minute) on the issuing route, defined next to the others in
   `AppServiceProvider`.
5. **Session list:** `AuthService::listTokens()` excludes widget tokens
   (`whereNull('parent_token_id')`), and `revokeToken()` treats a widget
   token id as not found. Revoking the parent session covers them.
6. **No change** to `refreshToken()`, `logout()`, `logoutAll()`: the cascade
   does the work. The app requests a new widget token after each refresh.
7. **Tests**, per `docs/TESTING.md`:
   - a widget token gets 401 on `GET /users/me`, `POST /auth/refresh`,
     `POST /auth/widget-token`, `GET /auth/tokens`; a normal token still works
     everywhere;
   - a widget token is accepted on a `widgets.*` route (register a temporary
     test route, since job 3 adds the real ones);
   - issuing twice from the same session leaves exactly one widget token;
     issuing from two sessions leaves one per session;
   - the widget token's expiry equals its parent's;
   - the widget token is deleted when its parent is deleted by logout,
     `revoke-token`, `logout-all`, a password change and `refresh`;
   - an expired parent's widget token is rejected (it shares the expiry);
   - widget tokens are absent from `GET /auth/tokens`.
8. OpenAPI annotations for the new endpoint, like the other auth endpoints.

## Out of scope

- Any `widgets.*` route (job 3).
- Scheduling `sanctum:prune-expired`. Nothing deletes expired tokens today;
  that is a separate entry.

## Notes

- This touches authentication: flag it in review.
- The ability name `widget` is informational. The gate keys on "has `*` or
  not", so any future restricted token type is denied by default as well.
- `RecordClientVersion` and `TrackApiTraffic` apply to widget requests like
  any other; native sends `X-Client-Version`.
