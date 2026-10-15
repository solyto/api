# Brief: Finance History

type: feature
id: river
branch: feature/river_finance-history
date: 2026-10-15
author: Leander Muskalla

## What

Make the value history of wealth fields and budget items editable: values can be added for any date, corrected and deleted, with at most one value per item and day.

**Wealth.** Today `PUT /finances/wealth/fields/{field}/value` always creates a new `WealthValue` dated today (`WealthService::updateValue`), and there is no way to change or remove a past value.

- Adding a value accepts an optional date (default: today). If the field already has a value on that date, it is replaced instead of a second one being created.
- A single value can be updated (amount and date) and deleted.
- `currentValue` on `WealthFieldResource` is the value with the latest date, not the last inserted one, and the field's values are returned ordered by date.

**Budget.** A budget item (income or expense) has a single `value` column that is overwritten on every change, so there is no history at all.

- Budget items get a dated value history like wealth fields, with the same operations: add with an optional date (replacing an existing value on that date), update and delete a single value.
- The item's current value is its value with the latest date. Existing reads of the budget item (list, totals) keep returning a current value, so clients that only show the current amount keep working.
- Existing budget items start their history with one value: their current `value`, dated at their `updated_at`.

**Both.**

- Only the owner of the item can add, change or delete its values.
- Caches for the user's finance data are invalidated on every change.

## Why

Users cannot fix a mistyped amount, remove a wrong entry, record a balance they forgot to enter on the day, or see how an income or expense changed over time, so their finance history drifts away from reality or does not exist (feature request "Finance - history", 27.08.2026).

## Out of scope

- Bulk import or bulk editing of values.
- Changes to creating, renaming or deleting wealth fields and budget items, beyond what the history needs.
- Recurring or scheduled budget changes (e.g. "salary rises from July").

## Notes

- `WealthFieldResource::currentValue` is currently `$this->values->last()` on an unordered relation. Once values can be backfilled, the last inserted value is no longer the most recent one, so this must be based on the date.
- Existing data can already contain several wealth values for the same field and day (every edit on a day added a row). The one-value-per-day rule needs a decision for those rows: the intended behaviour is to keep the most recently created value per field and day and drop the others. This deletes data, so confirm it with the owner before writing the migration.
- Income and expense items share the `budgets` table, so the history applies to both. The request mentions income; if the owner wants it for income only, that should be decided before the analyst starts.
- Whether a date in the future is allowed is open; rejecting it is the expected default.
- Endpoint shape and naming are up to the developer; the frontend job will follow whatever is chosen here. The OpenAPI docs should describe the new and changed endpoints.