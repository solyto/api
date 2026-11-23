# Brief: clipboard files left behind

type: feature
id: sure
branch: feature/sure_clipboard-files-left-behind
date: 2026-11-23
author: Leander Muskalla

## What

Ways clipboard files (up to 10 MB each since the file entries were added)
can stay on the private disk without a row, or rows stay listed after expiry.

1. **Expiry job race.** `DeleteOverdueClipboardEntries.php:22-31` selects
   overdue rows, deletes their files, then runs a second `delete()` that
   recomputes `now()->subDay()`. A row that becomes overdue between the two
   queries loses its row but keeps its file forever.
2. **Expired entries stay listed.** The job doesn't clear the user's cached
   clipboard list (24 h), so expired entries keep showing and their download
   returns 404. Same as before for images.
3. **Migration rollback.** `2026_11_16_120000_add_file_type_to_clipboards`
   `down()` deletes the file rows but not their files. In `2026_11_16_120001`,
   a failed move leaves that image on the public disk while the app looks on
   the private disk (404, nothing logged).

## Suggestion

1. Delete rows by the ids collected in the first query.
2. Clear the affected users' clipboard cache in the job.
3. Delete the files in `down()`; log failed moves in the move migration.
