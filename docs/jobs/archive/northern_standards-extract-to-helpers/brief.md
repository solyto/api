# Brief: Standards: extract to helpers

type: feature
id: northern
branch: feature/northern_standards-extract-to-helpers
date: 2026-10-13
author: Leander Muskalla

From `docs/STANDARDS_BACKLOG.md` → "Extract to helpers (small, each removes several copies)". Rule numbers refer to
`docs/STANDARDS.md`; counts are from 2026-10-13. Items marked **(approval)**
need the owner's go-ahead first.

- [ ] `TodoService`: `private function forgetUser(string $userId): void`
      for the 7× `forget`/`forget` pair (`:170-171, 208-209, 255-256,
      264-265, 275-276, 286-287, 297-298`); same in `TimeTrackingService`
      (19 calls), `CalendarService` (19), `ContactService` (9),
      `NoteService` (7) (rule 4.4, after OQ-3).
- [ ] `Export/Services/*`: `writeCsv(string $path, array $header, iterable $rows)`
      shared by the 10 CSV exporters (28–47 lines each); keep the five
      non-CSV ones as they are (findings-services §5).
- [ ] `LibraryBookService::recommend:110-119` and `LibraryMusicService:112-121`:
      `match ($type)` over the four `LibraryRecommendationEnum` cases, `NEW`
      handled inside; `LibraryBookController::recommend:131-156` and
      `LibraryMusicController:131-156` collapse to one call (rules 1.3, 4.3).
      Check: `grep -rn call_user_func app` → 0.
- [ ] `findGenre()` pass-throughs in `LibraryBookService:170`,
      `LibraryMovieService:166`, `LibraryGameService:126`,
      `LibraryMusicService:174`: delete, controllers return the bound genre
      (rule 4.5).
- [ ] `DevRequestResource.php:64` `comments->count()` → `withCount('comments')`
      in `DevRequestService` + `comments_count`; `DevRequestCommentResource.php:36`,
      `FeedSubscriptionResource.php:34` → `whenLoaded` + eager load (rule 6.1).
- [ ] Four `*SearchResultResource` files without `@OA\Schema` (rule 6.2);
      ten resources whose schema drifts from `toArray()` (`TodoResource`,
      `NotificationSettingsResource`, `UserResource`, `UserSettingsResource`,
      `DailyWeatherForecastResource`, `DevRequestCommentResource`,
      `LibraryRecipeResource`, `RecipeReleaseResource`, `FriendRequestResource`
      — list in `docs/jobs/dawn_standards/findings-data.md` §3) (rule 2.6).
- [ ] `TodoController.php:131-143,187-199`: add `link`, `recurrence_frequency`,
      `recurrence_interval`, `recurrence_ends_at` to both request-body
      annotations (rule 2.6).
