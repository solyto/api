# Backlog: malformed input that still returns 500

created: 2026-11-18

## What

Inputs that should be a 422 (or be cleaned up) but end in a 500. Found in
the release review after 1.1.3. None is reachable by normal app use.

1. **Timezone with a null byte.** `UpdateTimezoneRequest.php:20-26` catches
   `\Exception`, but `new DateTimeZone("Europe/Berlin\0")` throws `ValueError`
   (an `Error`). `{"timezone":"Europe/Berlin\u0000"}` → 500. The rule also
   accepts offsets and abbreviations (`+01:00`, `CEST`, `Z`), which then flow
   into `EventDTO` and the weather request. Not a regression.
2. **Invalid timezones already stored.** The new validation only stops new
   ones. Users who already have one:
   - get no reminders, and both reminder commands `report()` them every hour
     (up to 48 reports a day per user);
   - still get 500s where `new \DateTimeZone(...)` runs on the stored value
     (`CalendarController.php` ~551/~654, `EventDTO.php` ~71/~120).
   No migration or command fixes the rows.
3. **Passkeys with valid client data but broken authenticator data.**
   `PasskeyService.php` ~81-90 / ~176-183: authenticator data shorter than
   33 bytes reads `$authData[32]` (warning → exception → 500); a non-string
   `challenge`/`origin` inside the client data throws a `TypeError`. Needs a
   valid live challenge, so it's an edge case.

## Suggestion

1. Catch `\Throwable` (or reject `\0`) and only accept identifiers from
   `DateTimeZone::listIdentifiers()` (plus `UTC`).
2. A one-off command or migration that resets invalid stored timezones to
   `UTC`; then the reminder commands no longer need to report them every hour.
3. Length/type checks before parsing authenticator data; map failures to 422.

Tests for each: null-byte timezone → 422; invalid stored timezone is reset;
short authenticator data → 422.
