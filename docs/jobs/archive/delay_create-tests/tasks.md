# Tasks: create tests

id: delay
status: open
analyst:
date: 2026-08-13

<!-- Produced by @analyst from brief.md. -->

## Task breakdown

TASK-1: Create the test scaffolding (tests/Unit and tests/Feature directories with their own Pest.php, a shared helper for authenticated API requests, and a coverage configuration in phpunit.xml) that the rest of the suite builds on.
     files: tests/TestCase.php, tests/Unit/Pest.php, tests/Feature/Pest.php, phpunit.xml
     depends: none
     risk: medium — existing tests only cover factories/models, and the whole feature-test layer has to be bootstrapped against the current sqlite in-memory setup.

TASK-2: Reconfigure the test database setup so the hard-coded 'pgsql' connection (used by DavServerFactory, DAV backends and StatisticsService) resolves to the same SQLite database as the default connection.
     files: tests/TestCase.php
     depends: TASK-1
     risk: medium — SQLite in-memory databases are per-connection, so sharing one database across two named connections requires a file-backed or shared-cache workaround.

TASK-3: Write pure unit tests for the ApiResponse envelope (success/error/validationError/unauthorized/forbidden/notFound/serverError, JsonResource/ResourceCollection/paginator transformation, pagination meta).
     files: app/Api/ApiResponse.php, tests/Unit/ApiResponseTest.php
     depends: TASK-1
     risk: low — static self-contained class with no external dependencies.

TASK-4: Write unit tests for shared pure helpers and enums (UrlHelper, DockerSecretHelper, AuthPlatformEnum, AiUsageFeatureEnum, QuickAddContentType, DetectionResult, Keyboard DTO, SolytoMessage enum).
     files: app/Shared/Helpers/UrlHelper.php, app/Shared/Helpers/DockerSecretHelper.php, app/Shared/Enums/*, app/Api/Dashboard/Enums/QuickAddContentType.php, app/Api/Dashboard/DTOs/DetectionResult.php, app/Bots/DTOs/Keyboard.php, app/Bots/Messages/SolytoMessage.php, tests/Unit/*
     depends: TASK-1
     risk: low — pure value objects with no I/O.

TASK-5: Write unit tests for the DAV helpers (VCardHelper, ICalendarHelper, DavHelper, app/Dav/Helpers/UrlHelper.php), first verifying which helpers are still live code since ICalendarHelper references the removed App\Models\NextcloudCalendarEntry model.
     files: app/Dav/Helpers/*, tests/Unit/Dav/*
     depends: TASK-1
     risk: medium — some helpers appear to be legacy/dead code referencing models that no longer exist and may need refactoring or skipping.

TASK-6: Write feature tests for the auth flow (register, login, logout, logout-all, refresh, verify, forgot-password, reset-password, tokens, revoke-token) plus unit tests for AuthService edge cases (already-verified, token mismatch/expiry, current-token revoke guard).
     files: app/Api/Users/Controllers/AuthController.php, app/Api/Users/Services/AuthService.php, app/Api/Users/Requests/*, app/Api/Users/Mails/*, app/Api/Users/Tests/AuthTest.php
     depends: TASK-2
     risk: medium — needs Mail::fake, throttle-limit awareness and Sanctum token manipulation.

TASK-7: Write feature tests for user profile and settings endpoints (me, index/update users, public-profile, change-password, profile-image, and all me/settings sub-routes including check-in and onboarding).
     files: app/Api/Users/Controllers/UserController.php, app/Api/Users/Controllers/UserSettingsController.php, app/Api/Users/Services/UserService.php, app/Api/Users/Services/UserSettingsService.php, app/Api/Users/Services/PasswordService.php, app/Api/Users/Services/UserProfileImageService.php, app/Api/Users/Tests/UserApiTest.php
     depends: TASK-2
     risk: medium — profile image upload requires Storage::fake and image-generation mocking.

TASK-8: Write feature tests for the friends flow (list friends, list/store/accept/reject friend requests) plus FriendService unit tests.
     files: app/Api/Users/Controllers/FriendController.php, app/Api/Users/Services/FriendService.php, app/Api/Users/Notifications/FriendRequestNotification.php, app/Api/Users/Tests/FriendApiTest.php
     depends: TASK-2
     risk: medium — involves bidirectional relationships and notification dispatch.

TASK-9: Write feature and service tests for passkeys (register-options/register, authenticate-options/authenticate, index/update/destroy), mocking the WebAuthn library if needed.
     files: app/Api/Users/Controllers/PasskeyController.php, app/Api/Users/Services/PasskeyService.php, app/Api/Users/Requests/PasskeyRegisterRequest.php, app/Api/Users/Requests/PasskeyAuthenticateRequest.php, app/Api/Users/Tests/PasskeyTest.php
     depends: TASK-2
     risk: high — WebAuthn attestation/assertion involves crypto and the vendor library's behavior may need heavy mocking or a test seam.

TASK-10: Write feature and service tests for Todos (categories CRUD, workspaces CRUD plus attach/detach categories, todos CRUD with subtasks and due-date listing) and TodoService parse logic.
     files: app/Api/Todos/Controllers/*, app/Api/Todos/Services/*, app/Api/Todos/Requests/*, app/Api/Todos/Tests/TodoApiTest.php
     depends: TASK-2
     risk: medium — recurrence/parse logic and many nested routes increase surface area.

TASK-11: Write feature and service tests for Notes (notes CRUD, categories CRUD, newest, import/export) including NoteService and NoteImportService.
     files: app/Api/Notes/Controllers/*, app/Api/Notes/Services/*, app/Api/Notes/Requests/*, app/Api/Notes/Tests/NoteApiTest.php
     depends: TASK-2
     risk: medium — import/export endpoints touch file storage and JSON structures.

TASK-12: Write feature and service tests for Tags and Shortcuts (CRUD plus shortcut reorder).
     files: app/Api/Tags/*, app/Api/Shortcuts/*, app/Api/Tags/Tests/TagApiTest.php, app/Api/Shortcuts/Tests/ShortcutApiTest.php
     depends: TASK-2
     risk: low — simple CRUD with minimal side effects.

TASK-13: Write feature and service tests for Check-In (index/store including validation and per-user-per-date uniqueness) and CheckInService.
     files: app/Api/CheckIn/Controllers/CheckInController.php, app/Api/CheckIn/Services/CheckInService.php, app/Api/CheckIn/Requests/*, app/Api/CheckIn/Tests/CheckInApiTest.php
     depends: TASK-2
     risk: low — straightforward store/list flow.

TASK-14: Write feature and service tests for Clipboard (list/store, image upload/retrieval, delete) including ClipboardService and ClipboardImageService with Storage::fake.
     files: app/Api/Clipboard/Controllers/ClipboardController.php, app/Api/Clipboard/Services/*, app/Api/Clipboard/Requests/*, app/Api/Clipboard/Tests/ClipboardApiTest.php
     depends: TASK-2
     risk: medium — image endpoints need image-manipulation mocking.

TASK-15: Write feature and service tests for Time Tracking (categories, projects, entries start/stop/statistics) and TimeTrackingService logic.
     files: app/Api/TimeTracking/Controllers/*, app/Api/TimeTracking/Services/TimeTrackingService.php, app/Api/TimeTracking/Requests/*, app/Api/TimeTracking/Tests/TimeTrackingApiTest.php
     depends: TASK-2
     risk: medium — start/stop state transitions and statistics aggregation need careful fixtures.

TASK-16: Write feature and service tests for Finances (budget CRUD, wealth fields CRUD and value updates) plus BudgetService/WealthService.
     files: app/Api/Finances/Controllers/*, app/Api/Finances/Services/*, app/Api/Finances/Requests/*, app/Api/Finances/Tests/FinanceApiTest.php
     depends: TASK-2
     risk: medium — budget/wealth computation logic needs well-defined fixtures.

TASK-17: Write feature and service tests for the book and music library areas (CRUD, genres, search/import/releases/recommend) with Http::fake + fixture responses for the external services, plus DTO parsing tests.
     files: app/Api/Libraries/Controllers/LibraryBook*.php, app/Api/Libraries/Controllers/LibraryMusic*.php, app/Api/Libraries/Services/LibraryBookService.php, app/Api/Libraries/Services/LibraryMusicService.php, app/Api/Libraries/Services/External/{Hardcover,Goodreads,Deezer,Discogs}Service.php, app/Api/Libraries/Tests/LibraryApiTest.php
     depends: TASK-2
     risk: high — external API response shapes must be pinned with realistic fixtures to keep parsing tests stable.

TASK-18: Write feature and service tests for the remaining library areas (movies, games, links, quotes, recipes, plants and cover serving) with Http::fake + fixtures, plus the genre controllers.
     files: app/Api/Libraries/Controllers/Library{Game,Movie,Link,Quote,Recipe,Plant,Cover}*.php, app/Api/Libraries/Services/Library{Game,Movie,Link,Quote,Recipe,Plant,Cover}Service.php, app/Api/Libraries/Services/External/*.php, app/Api/Libraries/Tests/LibraryApiTest.php
     depends: TASK-2
     risk: high — many external providers and file-based cover endpoints.

TASK-19: Write unit tests for AiService, LibraryRecommender, LibraryReleases and the external DTOs, mocking the OpenAI client and external HTTP calls.
     files: app/Api/Libraries/Services/AiService.php, app/Api/Libraries/Services/LibraryRecommender.php, app/Api/Libraries/Services/LibraryReleases.php, app/Api/Libraries/DTOs/*, app/Api/Libraries/Services/External/*, app/Api/Libraries/Tests/LibraryServiceTest.php
     depends: TASK-17, TASK-18
     risk: medium — OpenAI client and HTTP responses must be mocked with representative fixtures.

TASK-20: Write feature and service tests for Calendars (list/store calendars, invites accept/decline, events CRUD with occurrences, attachments, sharing, import/select/state flow) and CalendarService/EventAttachmentService.
     files: app/Api/Calendars/Controllers/*, app/Api/Calendars/Services/*, app/Api/Calendars/Jobs/*, app/Api/Calendars/Requests/*, app/Api/Calendars/Tests/CalendarApiTest.php
     depends: TASK-2
     risk: high — recurrence, ICS parsing and the DAV backend coupling make this the most complex API domain.

TASK-21: Write feature and service tests for Contacts (address books CRUD, contacts CRUD with photos, import/select/state flow) and ContactService/ContactPhotoService.
     files: app/Api/Contacts/Controllers/*, app/Api/Contacts/Services/*, app/Api/Contacts/Jobs/*, app/Api/Contacts/Requests/*, app/Api/Contacts/Tests/ContactApiTest.php
     depends: TASK-2
     risk: high — vCard parsing and photo manipulation require careful mocking.

TASK-22: Write feature and service tests for Feeds (subscriptions CRUD, items, available/search/test endpoints, SyncFeed/SyncFeeds jobs) and FeedService/FeedReader.
     files: app/Api/Feeds/Controllers/FeedController.php, app/Api/Feeds/Services/*, app/Api/Feeds/Jobs/*, app/Api/Feeds/Exceptions/*, app/Api/Feeds/Tests/FeedApiTest.php
     depends: TASK-2
     risk: high — SimplePie is hard to mock, so FeedReader may need a test seam before the sync logic can be covered.

TASK-23: Write feature and unit tests for Weather (today endpoint with Http::fake) including WeatherService caching behavior and UserCacheService usage.
     files: app/Api/Weather/Controllers/WeatherController.php, app/Api/Weather/Services/WeatherService.php, app/Api/Weather/Resources/*, app/Api/Weather/Tests/WeatherTest.php
     depends: TASK-2
     risk: low — only external HTTP needs faking.

TASK-24: Write feature and unit tests for the Statistics overview endpoint and StatisticsService (requires the TASK-2 pgsql fix).
     files: app/Api/Statistics/Controllers/StatisticsController.php, app/Api/Statistics/Services/StatisticsService.php, app/Api/Statistics/Resources/StatisticsResource.php, app/Api/Statistics/Tests/StatisticsTest.php
     depends: TASK-2
     risk: medium — the hard-coded pgsql connection must be testable or the service needs a small refactor.

TASK-25: Write feature and unit tests for Dashboard quick-add (detect/commit endpoints) covering the QuickAddService detection matrix (URL types, keywords, confidence thresholds) and all commit paths with mocked services.
     files: app/Api/Dashboard/Controllers/QuickAddController.php, app/Api/Dashboard/Requests/*, app/Api/Dashboard/Resources/QuickAddResource.php, app/Shared/Services/QuickAddService.php, app/Api/Dashboard/Tests/QuickAddTest.php
     depends: TASK-2, TASK-17, TASK-18
     risk: medium — commit paths delegate to library import services that need mocking.

TASK-26: Write feature and service tests for DevRequests (index/store/update/vote/comments) and DevRequestService/DevRequestScreenshotService.
     files: app/Api/DevRequests/Controllers/DevRequestController.php, app/Api/DevRequests/Services/*, app/Api/DevRequests/Requests/*, app/Api/DevRequests/Tests/DevRequestApiTest.php
     depends: TASK-2
     risk: medium — screenshot handling and vote uniqueness add edge cases.

TASK-27: Write feature tests for Notifications (list, mark-all-read, mark-read, push subscribe/unsubscribe, notification settings) and the Telegram bot-connection endpoints (token-request, request, alert updates), mocking webpush and Telegram HTTP.
     files: app/Api/Notifications/Controllers/*, app/Api/Notifications/Requests/*, app/Api/Telegram/Controllers/TelegramBotConnectionController.php, app/Api/Telegram/Requests/*, app/Api/Notifications/Tests/*, app/Api/Telegram/Tests/TelegramApiTest.php
     depends: TASK-2
     risk: medium — webpush and Telegram APIs need HTTP/mock fakes.

TASK-28: Write tests for Export (store/status/download endpoints, every *ExportService output format, ProcessExport job success/failure, DeleteExpiredExports) with Storage::fake and the user_data disk.
     files: app/Api/Export/Controllers/ExportController.php, app/Api/Export/Services/*, app/Api/Export/Jobs/*, app/Api/Export/Commands/ExportUserData.php, app/Shared/Models/ExportJob.php, app/Shared/Notifications/ExportReadyNotification.php, app/Api/Export/Tests/ExportTest.php
     depends: TASK-2
     risk: medium — ZIP/CSV/ICS/VCF generation and job error paths need careful assertions on the fake disk.

TASK-29: Write tests for the Bots subsystem (SolytoBot entrypoint, connect/day/todos commands, quick-add type-selection state machine, ConversationState, Keyboard DTO, TelegramBotService) with Http::fake and a mocked IntegrationGateway.
     files: app/Bots/*, app/Shared/Services/TelegramBotService.php, app/Shared/Services/IntegrationGateway.php, app/Bots/Tests/*
     depends: TASK-2
     risk: medium — the conversation-state machine and command routing need careful scenario fixtures.

TASK-30: Write tests for the DAV services and backends (DavService, Events/Calendars/Contacts/AddressBooks, AppCalendarsPDO/AppContactsPDO CRUD, Principals, CalendarSharing, VCardPhotoProcessor, ImportService/CalendarImport/ContactImport) against the shared SQLite database from TASK-2.
     files: app/Dav/Backend/*, app/Dav/Services/*, app/Dav/Controllers/DavController.php, app/Dav/Auth/LaravelAuthBackend.php, app/Dav/Factories/DavServerFactory.php, app/Dav/Tests/*
     depends: TASK-2, TASK-5
     risk: high — Sabre PDO backends issue raw PostgreSQL-flavored SQL that may not be portable to SQLite and could need code adjustments.

TASK-31: Write queue-job tests covering the maintenance/import/release jobs (SyncFeed, SyncFeeds, ImportCalendars, RefreshCalendarCache, ImportContacts, RefreshContactsCache, GrabBook/Music/MovieReleases, ScaleCovers, GenerateCoverPreview, DeleteOldFeedItems, DeleteOldDevRequests, DeleteOverdueClipboardEntries, DeleteExpiredExports, DeleteOldFriendRequests, ScaleProfileImage, ProcessExport).
     files: app/Api/*/Jobs/*, app/Shared/Models/ExportJob.php, app/Bots/Commands/* (if invoked by jobs), app/Api/*/Tests/JobTest.php
     depends: TASK-2, TASK-17, TASK-18, TASK-22
     risk: medium — external HTTP, OpenAI and image-manipulation calls must be faked per job.

TASK-32: Write artisan-command tests (CreateUser, ListUsers, SearchUser, ChangePassword, ResetDavForUser, CreateDavPrincipalsForExistingUsers, SendDailyDayRemindersCommand, SendDailyCheckInRemindersCommand, RegisterBotWithTelegram, TelegramBotStatus, ExportUserData, BackfillFeedImages, ImportFeeds, ScaleContactPhotos).
     files: app/**/Commands/*, routes/console.php, app/**/Tests/CommandTest.php
     depends: TASK-2
     risk: medium — commands with external side effects (Telegram, images, DAV principals) need fakes.

TASK-33: Write a test that parses the OpenAPI spec (via l5-swagger annotations) and asserts every documented operation/path resolves to a registered route with matching HTTP verbs.
     files: app/OpenApi/OpenApiSpec.php, app/Api/**/Controllers/*, routes/api.php, tests/Unit/OpenApiSpecTest.php
     depends: TASK-1
     risk: low — read-only assertion over annotations and the route table.

TASK-34: Run the full suite (`composer test`) plus a coverage report, fix any flakes or testability blockers that surface, and record the achieved per-module coverage and remaining gaps in implementation.md.
     files: implementation.md, any tests touched by fixes
     depends: TASK-3 through TASK-33
     risk: medium — coverage on external-call-heavy modules will likely still be partial and needs honest reporting rather than forcing fragile tests.
