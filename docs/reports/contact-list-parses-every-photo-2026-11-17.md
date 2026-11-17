# Report: Contact list spends most of its time on photos it then discards

date: 2026-11-17
read: false
branch: `main`, tree at `073bfdc`
scope: Performance of `GET /address-books/contacts` (`ContactService::listContacts`) when the cache is empty or has just been cleared. Written for the maintainer. No application code, config, migrations, or DAV wiring/schema were changed. Throwaway probe scripts were run and deleted.

## Summary

**The contact list never returns photos, but it still loads and parses every photo byte by byte.** `AppContactsPDO::getContactsCustom` (`app/Dav/Backend/AppContactsPDO.php:119-133`) selects the full `carddata` of every card in an address book. Each card goes through `ContactDTO::fromSabre($row, $addressBook, false)`. `includePhoto = false` only means the photo isn't *stored* in the result. `parseVCard` (`app/Dav/DTOs/ContactDTO.php:276-412`) still runs **two regexes on every folded photo line** (`preg_replace` + `preg_replace_callback`, `:323-332`) before it notices the line belongs to the photo (`:343`) and skips it.

A 150 KB photo is about 2 000 folded lines of 74 characters. Photos may be up to `PHOTO_MAX_BYTES` = 200 KB (`ContactService.php:21`, enforced on write by `VCardPhotoProcessor`), so photos make up nearly all of a card's bytes and nearly all of the parse time.

The cached result is small (photos are dropped), so cache hits are cheap. The cost lands on every **rebuild**, which happens:
- after any create/update/delete/photo change in that address book through the API (`ContactService.php:125-170`),
- after any CardDAV `PUT`/`DELETE` from a phone or desktop client (`CacheInvalidationPlugin::invalidateContacts`). During a client's first sync this can happen once per card,
- when the 24 h TTL runs out.

## Measured

Probe: 300 cards in one address book, inserted straight into `cards` (no processor), then `listContacts` with an empty cache. SQLite in-memory, so this is the **PHP side only**. Production also pulls the `carddata` bytes over the network from PostgreSQL.

| photo per card | `carddata` total | rebuild | cached value |
|---|---|---|---|
| none | 0.04 MB | 21 ms | 184 KB |
| 30 KB | 12.2 MB | 155 ms | 184 KB |
| 150 KB | **61.0 MB** | **696 ms** | 184 KB |

Parser alone, 300 × 150 KB card, `includePhoto = false`:

| variant | time |
|---|---|
| current `parseVCard` | 685 ms |
| A: skip photo continuation lines before the regexes | 238 ms (2.9×) |
| B: strip the PHOTO block from the raw string first, then parse | 50–93 ms (7–14×) |

Both variants gave the same `parseVCard` result as today on the cases tried: a plain `PHOTO;ENCODING=b`, a grouped `item1.PHOTO`, a lowercase `photo` with LF line endings, and `PHOTO;VALUE=URI`. The one difference: when PHOTO is the **last** line, today's code returns `photo => ''` instead of `null` even with `includePhoto = false` (`:405-407` ignores the flag). `fromSabre` nulls it anyway (`:60`), so this doesn't matter to callers, but it's a small bug of its own.

## Suggestion

**1. (Recommended, small) Strip the photo before parsing when `$includePhoto` is false.** At the top of `parseVCard`:

```php
if (!$includePhoto) {
    $vcard = preg_replace('/^(?:[A-Za-z0-9-]+\.)?PHOTO[;:][^\r\n]*(?:\r?\n[ \t][^\r\n]*)*(?:\r?\n|$)/mi', '', $vcard);
}
```

One PCRE pass over the string replaces ~2 000 × 2 regex calls plus the per-line `explode`/`rtrim` copies. The whole change stays inside the DTO, with no schema, DAV route or API shape change. The same pattern is already used in `VCardPhotoProcessor::process` (`app/Dav/Services/VCardPhotoProcessor.php:17`). Moving that regex into a shared constant would keep the two from drifting apart.

Variant A (move the `$isPhoto && continuation` check above the two regexes) is safer if you'd rather not touch the input string, but it gets about 3× instead of 7–14×. The two can be combined.

Tests to add in `ContactApiTest` / `DavServiceTest`: the list skips a folded photo (plain, grouped, lowercase, last line), and the fields after the photo (`EMAIL`, `TEL`, `NOTE`) are still parsed.

**2. (Optional, larger, needs a flag because it touches `pgsql`) Don't transfer the photo bytes at all.** Even with fix 1, a rebuild still reads all `carddata` from PostgreSQL: 61 MB in the probe case, and libpq buffers the whole result set before the `while fetch` loop starts. Two ways to avoid that:
- strip the photo in SQL (`regexp_replace(convert_from(carddata, 'UTF8'), …)`). That moves the CPU cost to the database and breaks on non-UTF-8 cards, so it isn't clearly better.
- keep a photo-less copy or a "has photo" flag next to `cards`. That's a schema change on the SabreDAV tables and needs a maintainer decision.

I'd only go this way if rebuilds are still slow in production after fix 1.

**3. (Minor, same area) `POST /address-books/contacts/photos`** (`ContactService::getContactPhotos`, `:107-122`) runs two pgsql queries per requested contact (`getAddressBook` + `getCard`), with no cache, for up to 10 contacts per call. A client that loads avatars for 300 contacts makes 30 calls and 600 queries. Looking up each distinct address book once per request would halve the queries. Not measured. It's mentioned here because it's the path that *does* need the photo.

## Side note (not performance)

`app/Api/Contacts/Services/ContactPhotoService.php` isn't referenced anywhere in `app/`. Its `save()` also builds a broken path (`$userId . '/contacts' . $contact->id . $extension`, no `/` and no `.`). It looks like a leftover from before photos moved into the vCard. It could be a backlog entry for removal.

## Not checked

- Production numbers: how many users have large address books with photos, and how often rebuilds happen. Pulse could show the slow `GET /address-books/contacts` requests.
- PostgreSQL network transfer time and libpq memory for real address books.
