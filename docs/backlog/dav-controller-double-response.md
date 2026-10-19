# Backlog: DavController builds a second response after SabreDAV sent its own

created: 2026-10-18

## What

`app/Dav/Controllers/DavController.php:21-27` calls `$server->exec()`, which
already sends the response to the client
(`vendor/sabre/dav/lib/DAV/Server.php:310,467,490` → `Sapi::sendResponse`).
After that it wraps the Sabre response in a Laravel `response(...)`. When the
Sabre body is a stream (a GET of a calendar object or vCard: the `pgsql`
`bytea` columns come back as PDO streams), `Symfony\...\Response::setContent()`
throws `TypeError: ... must be of type ?string, resource given`.

Stop building the second response. `exec()` has already sent everything the
client needs.

## Why

`dav.log` on 2026-10-17 shows bursts of `DAV Factory or Server error` with
this TypeError (06:36:58, 11:52:13). On the `public/dav.php` entry point the
client already has its response, and `public/dav.php:25-34` catches and logs
the error, so the only effect is noise. But every streamed GET logs an ERROR,
and that hides real failures.

The `routes/web.php:10-11` routes (`dav/{path?}`, `dav.solyto.de`) run the
same controller through Laravel. There, the TypeError reaches Laravel's
handler after Sabre has already sent its output, and a string body is sent
twice. Check which entry point production actually serves before changing
it. The traces show `public/dav.php`.

## Steps

1. Confirm in the deployment repo which entry point the web server routes to
   (`public/dav.php` vs. `public/index.php` → `routes/web.php`).
2. In `DavController::handle`, return after `exec()` without
   building a Laravel response from `getBody()` (for the Laravel route,
   whatever no-op response doesn't send a second body).
3. Add a DAV test that GETs a single `.ics` and `.vcf` object and asserts the
   body is sent once and nothing is logged.

## Notes

- This touches DAV request handling: flag it before doing it (rule 12.6).
- Check: `grep -n "getBody" app/Dav/Controllers/DavController.php` → 0.
