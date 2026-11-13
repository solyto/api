# Backlog: calendar invite accept/decline must be scoped to the caller

created: 2026-11-13

## What

Any authenticated user can accept or decline another user's calendar share
invite, if they know that user's email. Declining deletes the share.

`PUT /calendars/invites/{token}/accept|decline` (`routes/api.php:195-196`)
pass the token straight to `AppCalendarsPDO::acceptShare()` /
`declineShare()` (`app/Dav/Backend/AppCalendarsPDO.php:508-522`), which run:

```sql
UPDATE calendarinstances SET share_invitestatus = 2 WHERE share_href = ?
DELETE FROM calendarinstances WHERE share_href = ?
```

The token is `share_href`, which is `mailto:<recipient email>`. It is an
identifier, not a secret, and nothing checks it belongs to the caller.
`CalendarService::acceptInvite()` / `declineInvite()` receive the user but
only use it for cache invalidation.

Reproduced on 2026-11-13 with a throwaway Pest test: Alice shares with
Friend; Mallory calls `PUT /calendars/invites/mailto:<friend email>/decline`;
200, and Friend's shared instance row is gone.

## Why

Authorization hole on user data, needs only an email address. `decline` also
deletes instances that were already accepted, so a user can lose a calendar
they were actively using.

This is our code, not Sabre: Sabre has no accept/decline (its stock
`updateInvites` auto-accepts). Our `updateInvites` override sets new invites
to "no response" (`AppCalendarsPDO.php:457`), and these two methods were added
on top. No DAV request reaches them, so the fix doesn't change DAV behaviour.

## Steps

1. Pass the user down: `CalendarService` → `CalendarSharing` →
   `AppCalendarsPDO::acceptShare()` / `declineShare()`.
2. Scope both queries to the caller's principal and to pending invites:
   ```sql
   UPDATE calendarinstances SET share_invitestatus = 2
   WHERE share_href = ? AND principaluri = ? AND share_invitestatus = 1
   DELETE FROM calendarinstances
   WHERE share_href = ? AND principaluri = ? AND access > 1 AND share_invitestatus = 1
   ```
   The unique index `(principaluri, share_href)` means this matches at most
   one row, so the token format and the app contract stay as they are.
3. Return 404 when no row matched, instead of a silent 200. Check the app
   (`app/src/lib/config/apiRoutes.ts:354-355` and their callers) handles it.
4. Tests in `app/Api/Calendars/Tests/CalendarApiTest.php` (see
   `docs/TESTING.md`):
   - Another user accepting someone else's invite: 404, recipient's row still
     pending.
   - Another user declining someone else's invite: 404, row still there.
   - Recipient declining an already accepted share: 404, row still there
     (leaving goes through unsubscribe).
   - Recipient accepting / declining their own pending invite still works.
   - Accepting twice: second call 404, status stays accepted.

## Out of scope

- Sharing a second calendar with the same person returns 500 (unique index
  `(principaluri, share_href)` plus `mailto:` hrefs, inherited from Sabre's
  schema). Separate entry if it matters.
- Cache invalidation for sharees on event writes (separate work).

## Notes

- Check: the five tests above pass; a non-recipient can't change any row via
  the invite endpoints.
