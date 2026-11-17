# Backlog: tag changes leave cached lists stale

created: 2026-11-17

## What

When a tag is renamed, recoloured or deleted, also clear the cached lists
that include tags. Today `TagService` (`app/Api/Tags/Services/TagService.php`)
only clears its own `tags` cache in `update()` and `destroy()`. There are no
events or observers on `Tag` that clear anything else.

Cached lists (24 h TTL) that embed the tags:

| Component | Cache key | Loads tags |
|---|---|---|
| Budget | `budgets_v2` | `BudgetService.php:27` |
| Notes (list, hidden) | `notes_v2`, `notes_hidden_v2` | `NoteService.php:36, 53` |
| Todos | `todos` | `TodoService.php:35` |
| Books, movies, games | `books`, `movies`, `games` | `Library{Book,Movie,Game}Service.php` |
| Links, quotes | `links`, `quotes` | `LibraryLinkService.php:29`, `LibraryQuoteService.php:22` |
| Calendar attachments (todos) | `event_attachment_todos` (per event, prefix) | `EventAttachmentService.php:32` |

Check `todos_due` and `notes_newest_v2` / `notes_recent` too. They don't
load tags in the queries above, but confirm before leaving them out.

## Why

After a tag change, every list above shows the old tag name and colour for up
to 24 hours, or until something in that list changes. A deleted tag stays
visible on items. The database is correct (pivot rows cascade); only the
cache is stale.

Confirmed with a temporary test (2026-11-17): warm the list, rename the tag,
then delete it. The budget list and the note list return `["old"]` after the
rename and still `["old"]` after the delete. Todos and the libraries follow
the same pattern in code; not tested separately.

Not new: notes, todos and libraries have had this since tags exist. Budgets
joined in the budget tags job. Found by the owner while reviewing the
`budgets_v2` cache key change (2026-11-17).

## Options

**1. `TagService` clears the affected list keys** (small)

- A constant list of the keys above, forgotten in `update()` and
  `destroy()`. `event_attachment_todos` needs `forgetByPrefix()`.
- Weak spot: every new component with tags must be added to the list, or the
  bug comes back. A test that fails for a component missing from it would
  help.

**2. Clear all of the user's short-lived caches on a tag change** (simplest,
covers future components)

- Reuse the loops of `UserService::forgetUserCache()` (`:270`, private, used
  for account deletion). Tag changes are rare, so every list loading fresh
  once afterwards is acceptable.
- Must **not** clear the longterm store (`USER_LONGTERM_CACHE_KEYS`, the
  `*_release_notified` keys). Clearing it would re-send release
  notifications. So extract only the `USER_CACHE_KEYS` and
  `USER_CACHE_PREFIX_KEYS` part into a shared method.
- `USER_CACHE_PREFIX_KEYS` goes through `forgetByPrefix()`, which uses Redis
  `KEYS`. See `cache-forget-by-prefix-without-keys.md`.

**3. Leave it.** Stale for at most a day after a tag change.

## Decisions

- Undecided: option 1 or 2. Leaning towards 2 (robust, simple, cost only on
  rare tag changes).

## Tests (if picked up)

Per component group (budget, notes, todos, one library): warm the list,
rename the tag, and the list shows the new name. Delete the tag, and the
list no longer has it. Note that tests run against the `array` cache store
(`phpunit.xml`), so the cache is warm within one test.
