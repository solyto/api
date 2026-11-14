# Report: Every library cover response sleeps for one second

date: 2026-11-14
read: true
branch: `main`, tree at `1176396`
scope: Performance of `GET /libraries/covers/{type}/{fileName}`. Written for the maintainer. No application code, config or migrations were changed.

## Summary

**`LibraryCoverController::show` calls `sleep(1)` before returning every cover image** (`app/Api/Libraries/Controllers/LibraryCoverController.php:68`). It has been there since the initial commit (`37f9642`). Nothing in the code explains it. It looks like a leftover from testing the blur-up preview in the frontend.

This is the endpoint behind every book, music, movie and game cover. Each cover usually means two requests: the `_preview` placeholder (quality 1, made by `GenerateCoverPreview`) and the full image. Both go through `show`, so both wait one second.

The response also has no cache headers. Symfony then sends `Cache-Control: no-cache, private`, so the browser revalidates every cover on every view and pays the second again.

**Measured** (existing test, in-memory SQLite):

| test | time |
|---|---|
| `Cover serving → serves a stored cover image` | **2.65 s** |
| `Cover serving → returns 404 for missing covers` (returns before the sleep) | 0.05 s |

**What this costs in production:**

- **Latency.** A library page with 40 items makes about 80 cover requests. Over HTTP/1.1, the browser allows 6 connections per host, so the last cover shows up after about 80 / 6 ≈ 14 s, even though each file is read from local disk in milliseconds.
- **Worker starvation.** A sleeping PHP worker is still a busy worker. Over HTTP/2 the requests arrive in parallel, and each one holds an FPM worker for at least 1 s. One user opening a library can use up the whole pool (the size is set in the deployment repo). Every other API call, from any user, then waits in the queue: todos, calendar, widgets.

The suite also loses about 1 s on every test that serves a cover.

## 1. Where it happens

```php
// app/Api/Libraries/Controllers/LibraryCoverController.php:50-71
public function show(Request $request, LibraryTypeEnum $type, string $fileName)
{
    $service = new LibraryCoverService;
    $cover = $service->loadCover($request->user()->id, $type, $fileName);
    ...
    sleep(1);

    return response($cover)->header('Content-Type', $mimeType);
}
```

- `loadCover` reads the whole file into memory (`Storage::get`) and builds the response from that string.
- File names come from `uniqid()` in `LibraryCoverService::storeFile` and are never reused. A new upload gets a new name, and the `_preview` file is derived from it. So the bytes behind a URL never change.

## 2. Suggested fix

1. **Delete `sleep(1);`.** This is the main fix. It is one line, the API does not change, and the two existing cover tests keep passing.
2. **Let browsers cache covers.** File names are immutable (see §1), so this is safe:
   `->header('Cache-Control', 'private, max-age=31536000, immutable')`.
   `private` keeps shared proxies from storing the image, because the endpoint is behind `auth:sanctum`. This removes the repeat requests completely, not only the delay.
3. Optional: serve the file as a stream instead of a string, for example `Storage::disk('user_data')->response($path, null, $headers)`. The worker then does not hold the full image in memory. The gain is small, because covers are already scaled.

Tests: add `assertHeader('Cache-Control', ...)` to `serves a stored cover image` in `app/Api/Libraries/Tests/LibraryRestApiTest.php`. After the fix, that test should drop from about 2.6 s to well under 0.1 s.

## 3. Also worth checking (not verified)

The `user_data` disk has its root under `storage/app/public/user` and `visibility: public`. Its URL is `APP_URL/storage/user` (`config/filesystems.php:41-47`). This repo has no `public/storage` symlink. But if the deployment runs `php artisan storage:link`, every cover is reachable without authentication at `/storage/user/{userId}/{type}/{file}`. The authenticated endpoint would then only add latency. Contact photos are not affected, because they use `private_user_data`. Please check the deployment repo. If the link exists, either move covers to `private_user_data` or drop the link for `user/`. Which one depends on whether covers should be private.
