# Backlog: Remove Log::info narration in import and refresh jobs

created: 2026-10-14

## What
Remove the five narration `Log::info` calls in `Calendars/Jobs/ImportCalendars.php:34,37`, `Contacts/Jobs/ImportContacts.php:34,37` and `Contacts/Jobs/RefreshContactsCache.php:28` (rule 4.9).

## Why
They log normal progress on every run. Rule 4.9 keeps `Log` for things someone acts on. Check: `grep -rn 'Log::info' app/Api/*/Services app/Api/*/Jobs` → 0 (5 today).

## Out of scope
The jobs' `$tries`/`$timeout` (owner decision 2026-10-14: the import jobs stay on defaults) and their import loops.

## Notes
The import jobs write to the DAV `pgsql` database; this change only touches log lines, but say so in the job. Left over from `f3545db` (TASK-17), which listed other files.
