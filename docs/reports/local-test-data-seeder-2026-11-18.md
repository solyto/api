# Report: a seeder for local test data

date: 2026-11-18
branch: `main`, tree at `e42264a`
scope: The maintainer wants to stop testing locally with their own real data, because a real data export (`.zip`) was accidentally committed to this repo. The goal is a seeder that creates one test account full of fake data across every module, so a stray export or dump can't contain anything real. It's for local dev machines only and must never run in production. This is a plan. No code, config, migrations or `.gitignore` were changed.
read: true

## Summary

- **There is no seeder today.** `database/seeders/` doesn't exist, although `composer.json` already autoloads `Database\Seeders\`.
- **The factories cover almost everything.** There are 52 factories across the modules, and nearly all of them have a `forUser(User $user)` state.
- **Calendars and contacts are the exception.** The API reads them only from the DAV store (`pgsql`), through `CalendarService` and `ContactService`. The `Calendar`, `CalendarEntry`, `AddressBook` and `Contact` factories write to MariaDB tables that the API never reads, so the seeder has to create events and contacts through the DAV services.
- **Writing to `pgsql` while seeding is approved, for local dev only.** No schema, connection or route changes are involved.

## 1. Findings

### 1.1 Creating a user already does the base setup

`App\Api\Users\Observers\UserObserver::created()` (registered in `AppServiceProvider`) creates:

- a `UserProfile`
- `UserSettings`
- the journal `NoteCategory` (`NoteCategory::provisionJournal`)
- a default DAV calendar ("My Calendar") and a default address book, on `pgsql`

So `User::factory()->create()` produces a user set up exactly like one who registered. It also means seeding needs a running local `pgsql`.

### 1.2 Calendars and contacts live in DAV

- `CalendarService` (`app/Api/Calendars/Services/CalendarService.php`) works entirely through `DavService`:
  - calendars: `create(User, array)`
  - events: `createEvent(User, CalendarDTO, EventDTO)`, with `EventDTO::fromRequest(array, CalendarDTO, User)`
- `ContactService` (`app/Api/Contacts/Services/ContactService.php`) works the same way:
  - address books: `createAddressBook(User, array)`
  - contacts: `createContact(User, AddressBookDTO, array)`, with `ContactDTO::fromRequest`
- `EventDTO::fromRequest` reads `$user->settings->timezone`. The observer creates the settings row, so the seeder needs to load that relation first.

### 1.3 Account deletion already exists

`UserService::destroy(User)` removes everything a user owns:

- DAV data on `pgsql`
- tokens, notifications, push subscriptions and sessions
- the user row (foreign-key cascades remove the rest)
- both storage directories
- the user's cache keys

The seeder can use it to delete and recreate the test account on every run.

## 2. Plan

1. **Production guard.** The first thing `DatabaseSeeder::run()` does is stop with an error if `app()->environment('production')`. Laravel's `--force` prompt alone isn't enough.
2. **Re-runnable.** If the test user already exists (found by email), the seeder deletes it with `UserService::destroy()` before creating it again. Every run starts clean, including DAV data and storage, and no tables are dropped.
3. **Test user.** Email `test@solyto.local`, password `password` (the `UserFactory` default), verified, role `user`.
4. **Module data, from factories** (a few to a dozen rows each, all `forUser($user)`):
   - **Todos:** workspaces, categories, todos in mixed states (overdue, due today, completed), subtasks.
   - **Notes:** a small category tree with notes in it.
   - **Libraries:**
     - books, games, movies and series, music, plants, quotes and recipes
     - links with link categories
     - genres for each media type
   - **Finances:** budgets with past `BudgetValue`s, wealth fields with past `WealthValue`s.
   - **Time tracking:** categories, projects and entries, including one running entry.
   - **Other:** check-ins for the last 30 days, clipboard items, shortcuts, tags, notification settings.
5. **Calendars and contacts, through the services:**
   - **Calendars:** a second calendar (e.g. "Work"), plus about 20 events across this month and next. They include all-day, timed and one recurring event.
   - **Contacts:** about 15 contacts in the default address book.
   - All values come from `fake()`.
6. **A second test user** (e.g. `friend@solyto.local`, same password), with:
   - a friendship with the main test user, plus one pending friend request
   - a calendar shared between the two
7. **The modules that reach remote services are included too** (decided 2026-11-18), with fake data:
   - **Feeds:** a few real public RSS URLs, so the hourly sync works.
   - **External calendar accounts and calendars:** fake credentials. Scheduled syncs will fail for these, and that's expected.
   - **Telegram bot connections:** fake chat ids. Nothing can be delivered to them.
   - **AI `UserPlugin`s:** fake keys.
   - **Dev requests**, with comments and votes.
8. **Docs.** A short note on how to run it (`php artisan db:seed`).
9. **Verification.**
   - Run the seeder twice against the local DB and check the account in the frontend.
   - Run `composer test` and `composer analyse`, which must both exit 0.

## 3. Wiping existing real data locally

The seeder only replaces its own test account. To remove the maintainer's real account data from the local machine, either:

- **Delete the account through the app.** This goes through the same `UserService::destroy()` and clears DAV data too.
- **Do a full reset with `php artisan migrate:fresh --seed`.** Still to check: whether this also resets `pgsql`. If it doesn't, old DAV rows would be left behind and would need clearing separately. Commands are shown before anything is run.

## 4. Decisions (2026-11-18)

- **Every module is seeded**, including feeds, external calendars, Telegram, AI plugins and dev requests (§2.7).
- **A second test user is added** for friends and sharing (§2.6).
- **`*.zip` and `*.sql` are in `.gitignore`**, so exports and DB dumps can't be committed again. Neither file type was tracked when the rules were added.
