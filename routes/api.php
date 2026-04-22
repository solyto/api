<?php

use Illuminate\Support\Facades\Route;

use App\Api\Calendars\Controllers\CalendarController;
use App\Api\Calendars\Controllers\ImportController as CalendarImportController;
use App\Api\CheckIn\Controllers\CheckInController;
use App\Api\Clipboard\Controllers\ClipboardController;
use App\Api\Contacts\Controllers\ContactController;
use App\Api\Contacts\Controllers\ImportController as AddressBookImportController;
use App\Api\DevRequests\Controllers\DevRequestController;
use App\Api\Export\Controllers\ExportController;
use App\Api\Feeds\Controllers\FeedController;
use App\Api\Finances\Controllers\BudgetController;
use App\Api\Finances\Controllers\WealthController;
use App\Api\Libraries\Controllers\LibraryBookController;
use App\Api\Libraries\Controllers\LibraryBookGenreController;
use App\Api\Libraries\Controllers\LibraryCoverController;
use App\Api\Libraries\Controllers\LibraryGameController;
use App\Api\Libraries\Controllers\LibraryGameGenreController;
use App\Api\Libraries\Controllers\LibraryLinkCategoryController;
use App\Api\Libraries\Controllers\LibraryLinkController;
use App\Api\Libraries\Controllers\LibraryMovieController;
use App\Api\Libraries\Controllers\LibraryMovieGenreController;
use App\Api\Libraries\Controllers\LibraryMusicController;
use App\Api\Libraries\Controllers\LibraryMusicGenreController;
use App\Api\Libraries\Controllers\LibraryQuoteController;
use App\Api\Libraries\Controllers\LibraryRecipeController;
use App\Api\Notes\Controllers\NoteCategoryController;
use App\Api\Notes\Controllers\NoteController;
use App\Api\Notes\Controllers\NotesImportExportController;
use App\Api\Notifications\Controllers\NotificationSettingsController;
use App\Api\Notifications\Controllers\PushNotificationController;
use App\Api\Shortcuts\Controllers\ShortcutController;
use App\Api\Statistics\Controllers\StatisticsController;
use App\Api\Tags\Controllers\TagController;
use App\Api\Telegram\Controllers\TelegramBotConnectionController;
use App\Api\TimeTracking\Controllers\TimeTrackingCategoryController;
use App\Api\TimeTracking\Controllers\TimeTrackingEntryController;
use App\Api\TimeTracking\Controllers\TimeTrackingProjectController;
use App\Api\Todos\Controllers\TodoCategoryController;
use App\Api\Todos\Controllers\TodoController;
use App\Api\Todos\Controllers\TodoWorkspaceController;
use App\Api\Users\Controllers\AuthController;
use App\Api\Users\Controllers\FriendController;
use App\Api\Users\Controllers\NotificationController;
use App\Api\Users\Controllers\UserController;
use App\Api\Users\Controllers\UserSettingsController;
use App\Api\Weather\Controllers\WeatherController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {
    Route::get('health', function () {
        return response()->json([
            'status' => 'OK',
            'timestamp' => now(),
            'version' => '1.0.0'
        ]);
    });

    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:auth-register')->post('register', [AuthController::class, 'register']);
        Route::middleware('throttle:auth-login')->post('login', [AuthController::class, 'login']);
        Route::middleware('throttle:auth-login')->post('verify', [AuthController::class, 'verify']);
    });
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logoutAll', [AuthController::class, 'logoutAll']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('tokens', [AuthController::class, 'tokens']);
        Route::post('revokeToken', [AuthController::class, 'revokeToken']);
    });

    Route::prefix('users')->group(function () {
        Route::get('me', [UserController::class, 'me']);
        Route::post('me/profile-image', [UserController::class, 'updateProfileImage']);
        Route::put('change-password', [UserController::class, 'changePassword']);
        Route::get('{user}/public-profile', [UserController::class, 'publicProfile']);

        Route::prefix('me/settings')->group(function () {
            Route::put('navigation', [UserSettingsController::class, 'updateNavigation']);
            Route::put('timezone', [UserSettingsController::class, 'updateTimezone']);
            Route::put('language', [UserSettingsController::class, 'updateLanguage']);
            Route::put('date-format', [UserSettingsController::class, 'updateDateFormat']);
            Route::put('time-format', [UserSettingsController::class, 'updateTimeFormat']);
            Route::put('weather-city', [UserSettingsController::class, 'updateWeatherCity']);
            Route::put('weather-temperature-unit', [UserSettingsController::class, 'updateWeatherTemperatureUnit']);
            Route::put('openai-api-key', [UserSettingsController::class, 'updateOpenaiApiKey']);
            Route::put('complete-onboarding', [UserSettingsController::class, 'completeOnboarding']);
            Route::get('check-in', [UserSettingsController::class, 'showCheckIn']);
            Route::put('check-in', [UserSettingsController::class, 'updateCheckIn']);
        });
    });
    Route::apiResource('users', UserController::class);

    Route::prefix('todos')->name('todos.')->group(function () {
        Route::apiResource('categories', TodoCategoryController::class);

        Route::prefix('workspaces')->name('workspaces.')->group(function () {
            Route::post('{workspace}/categories/attach', [TodoWorkspaceController::class, 'attachCategories']);
            Route::post('{workspace}/categories/detach', [TodoWorkspaceController::class, 'detachCategories']);
            Route::apiResource('/', TodoWorkspaceController::class)->parameters(['' => 'workspace']);
        });

        Route::get('due-date', [TodoController::class, 'listDueDate']);
        Route::post('{todo}/subtasks', [TodoController::class, 'addSubtask']);
        Route::put('{todo}/subtasks/{subtask}', [TodoController::class, 'updateSubtask']);
        Route::delete('{todo}/subtasks/{subtask}', [TodoController::class, 'destroySubtask']);

        Route::apiResource('/', TodoController::class)->parameters(['' => 'todo']);
    });

    Route::apiResource('tags', TagController::class);

    Route::prefix('notes')->name('notes.')->group(function () {
        Route::post('import', [NotesImportExportController::class, 'import']);
        Route::get('export', [NotesImportExportController::class, 'export']);
        Route::apiResource('categories', NoteCategoryController::class);
        Route::get('newest', [NoteController::class, 'newest']);

        Route::apiResource('/', NoteController::class)->parameters(['' => 'note']);
    });

    Route::prefix('check-in')->group(function () {
        Route::get('', [CheckInController::class, 'index']);
        Route::post('', [CheckInController::class, 'store']);
    });

    Route::prefix('calendars')->group(function () {
        Route::get('', [CalendarController::class, 'listCalendars']);
        Route::post('', [CalendarController::class, 'storeCalendar']);

        Route::prefix('import')->group(function () {
            Route::post('', [CalendarImportController::class, 'startImport']);
            Route::post('select', [CalendarImportController::class, 'selectCalendars']);
            Route::get('state', [CalendarImportController::class, 'getState']);
        });

        Route::prefix('invites')->group(function () {
            Route::get('', [CalendarController::class, 'listInvites']);
            Route::put('{token}/accept', [CalendarController::class, 'acceptInvite']);
            Route::put('{token}/decline', [CalendarController::class, 'declineInvite']);
        });

        Route::prefix('events')->group(function () {
            Route::get('widget', [CalendarController::class, 'listWidgetEvents']);
            Route::get('{yearMonth}', [CalendarController::class, 'listEvents']);
        });

        Route::put('{instanceId}', [CalendarController::class, 'updateCalendar']);
        Route::delete('{instanceId}', [CalendarController::class, 'destroyCalendar']);
        Route::delete('{instanceId}/unsubscribe', [CalendarController::class, 'unsubscribeFromCalendar']);

        Route::prefix('{instanceId}/share')->group(function () {
            Route::post('', [CalendarController::class, 'shareCalendar']);
            Route::delete('{userId}', [CalendarController::class, 'revokeShare']);
        });

        Route::prefix('{instanceId}/events')->group(function () {
            Route::post('', [CalendarController::class, 'storeEvent']);
            Route::put('{eventUri}', [CalendarController::class, 'updateEvent']);
            Route::delete('{eventUri}', [CalendarController::class, 'destroyEvent']);
            Route::put('{eventUri}/occurrence/{occurrenceDate}', [CalendarController::class, 'updateEventOccurrence']);
            Route::delete('{eventUri}/occurrence/{occurrenceDate}', [CalendarController::class, 'destroyEventOccurrence']);
        });
    });

    Route::prefix('address-books')->group(function () {
        Route::get('', [ContactController::class, 'listAddressBooks']);
        Route::post('', [ContactController::class, 'storeAddressBook']);

        Route::prefix('import')->group(function () {
            Route::post('', [AddressBookImportController::class, 'startImport']);
            Route::post('select', [AddressBookImportController::class, 'selectAddressBooks']);
            Route::get('state', [AddressBookImportController::class, 'getState']);
        });

        Route::get('contacts', [ContactController::class, 'listContacts']);
        Route::post('contacts/photos', [ContactController::class, 'getContactPhotos']);

        Route::put('{addressBookId}', [ContactController::class, 'updateAddressBook']);
        Route::delete('{addressBookId}', [ContactController::class, 'destroyAddressBook']);

        Route::prefix('{addressBookId}/contacts')->group(function () {
            Route::post('', [ContactController::class, 'storeContact']);
            Route::put('{contactUri}', [ContactController::class, 'updateContact']);
            Route::delete('{contactUri}', [ContactController::class, 'destroyContact']);
            Route::post('{contactUri}/photo', [ContactController::class, 'updateContactPhoto']);
            Route::delete('{contactUri}/photo', [ContactController::class, 'removeContactPhoto']);
        });
    });

    Route::prefix('weather')->group(function () {
        Route::get('today', [WeatherController::class, 'today']);
    });

    Route::prefix('libraries')->group(function () {
        Route::prefix('covers')->group(function () {
            Route::get('{type}/{fileName}', [LibraryCoverController::class, 'show']);
            Route::options('{type}/{fileName}', [LibraryCoverController::class, 'options']);
        });

        Route::prefix('music')->name('music.')->group(function () {
            Route::apiResource('genres', LibraryMusicGenreController::class);
            Route::get('recommend/{type}', [LibraryMusicController::class, 'recommend']);
            Route::get('releases', [LibraryMusicController::class, 'releases']);
            Route::get('search/deezer/{artist}/{album}', [LibraryMusicController::class, 'searchAlbumOnDeezer']);
            Route::post('import/deezer', [LibraryMusicController::class, 'importAlbumFromDeezer']);
            Route::post('import/discogs', [LibraryMusicController::class, 'importAlbumFromDiscogs']);

            Route::apiResource('/', LibraryMusicController::class)->parameters(['' => 'music']);
        });

        Route::prefix('books')->name('books.')->group(function () {
            Route::apiResource('genres', LibraryBookGenreController::class);
            Route::get('recommend/{type}', [LibraryBookController::class, 'recommend']);
            Route::get('releases', [LibraryBookController::class, 'releases']);
            Route::post('import/hardcover', [LibraryBookController::class, 'importBookFromHardcover']);
            Route::post('import/goodreads', [LibraryBookController::class, 'importBookFromGoodreads']);

            Route::apiResource('/', LibraryBookController::class)->parameters(['' => 'book']);
        });

        Route::prefix('links')->name('links.')->group(function () {
            Route::apiResource('categories', LibraryLinkCategoryController::class);
            Route::get('newest', [LibraryLinkController::class, 'newest']);

            Route::apiResource('/', LibraryLinkController::class)->parameters(['' => 'link']);
        });

        Route::prefix('quotes')->name('quotes.')->group(function () {
            Route::get('random', [LibraryQuoteController::class, 'random']);

            Route::apiResource('/', LibraryQuoteController::class)->parameters(['' => 'quote']);
        });

        Route::apiResource('recipes', LibraryRecipeController::class);

        Route::prefix('movies')->name('movies.')->group(function () {
            Route::apiResource('genres', LibraryMovieGenreController::class);
            Route::post('import/imdb', [LibraryMovieController::class, 'importMovieFromImdb']);

            Route::apiResource('/', LibraryMovieController::class)->parameters(['' => 'movie']);
        });

        Route::prefix('games')->name('games.')->group(function () {
            Route::apiResource('genres', LibraryGameGenreController::class);
            Route::post('import/steam', [LibraryGameController::class, 'importGameFromSteam']);
            Route::post('import/bgg', [LibraryGameController::class, 'importGameFromBgg']);

            Route::apiResource('/', LibraryGameController::class)->parameters(['' => 'game']);
        });
    });

    Route::prefix('dev-requests')->group(function () {
        Route::get('', [DevRequestController::class, 'index']);
        Route::post('', [DevRequestController::class, 'store']);
        Route::put('{devRequest}', [DevRequestController::class, 'update']);
        Route::delete('{devRequest}', [DevRequestController::class, 'destroy']);
        Route::post('{devRequest}/vote', [DevRequestController::class, 'vote']);
        Route::get('{devRequest}/comments', [DevRequestController::class, 'listComments']);
        Route::post('{devRequest}/comments', [DevRequestController::class, 'storeComment']);
    });

    Route::prefix('telegram')->group(function () {
        Route::get('token-request', [TelegramBotConnectionController::class, 'getToken']);
        Route::get('request', [TelegramBotConnectionController::class, 'getRequest']);
        Route::put('your-day-alert', [TelegramBotConnectionController::class, 'updateYourDayAlert']);
        Route::put('check-in-alert', [TelegramBotConnectionController::class, 'updateCheckInAlert']);
    });

    Route::prefix('finances')->group(function () {
        Route::prefix('wealth/fields')->group(function () {
            Route::get('', [WealthController::class, 'listFields']);
            Route::post('', [WealthController::class, 'storeField']);
            Route::put('{field}', [WealthController::class, 'updateField']);
            Route::delete('{field}', [WealthController::class, 'destroyField']);
            Route::put('{field}/value', [WealthController::class, 'updateValue']);
        });

        Route::apiResource('budget', BudgetController::class);
    });

    Route::prefix('feeds')->group(function () {
        Route::get('items', [FeedController::class, 'listItems']);
        Route::get('available', [FeedController::class, 'availableFeeds']);
        Route::get('search', [FeedController::class, 'searchFeeds']);
        Route::get('friends', [FeedController::class, 'friendFeeds']);
        Route::post('test', [FeedController::class, 'testFeed']);

        Route::prefix('subscriptions')->group(function () {
            Route::get('', [FeedController::class, 'listSubscriptions']);
            Route::post('', [FeedController::class, 'storeSubscription']);
            Route::get('{feedSubscription}', [FeedController::class, 'showSubscription']);
            Route::put('{feedSubscription}', [FeedController::class, 'updateSubscription']);
            Route::delete('{feedSubscription}', [FeedController::class, 'destroySubscription']);
        });
    });

    Route::apiResource('shortcuts', ShortcutController::class);

    Route::get('statistics/overview', [StatisticsController::class, 'overview']);

    Route::prefix('friends')->group(function () {
        Route::get('', [FriendController::class, 'listFriends']);

        Route::prefix('requests')->group(function () {
            Route::get('', [FriendController::class, 'listFriendRequests']);
            Route::post('', [FriendController::class, 'storeFriendRequest']);
            Route::put('{friendRequest}/accept', [FriendController::class, 'acceptFriendRequest']);
            Route::put('{friendRequest}/reject', [FriendController::class, 'rejectFriendRequest']);
        });
    });

    Route::prefix('notifications')->group(function () {
        Route::get('', [NotificationController::class, 'list']);
        Route::put('{notification}/read', [NotificationController::class, 'markRead']);

        Route::prefix('push')->group(function () {
            Route::get('vapid-key', [PushNotificationController::class, 'getPublicKey']);
            Route::post('subscribe', [PushNotificationController::class, 'subscribe']);
            Route::post('unsubscribe', [PushNotificationController::class, 'unsubscribe']);
        });

        Route::prefix('settings')->group(function () {
            Route::get('', [NotificationSettingsController::class, 'show']);
            Route::put('', [NotificationSettingsController::class, 'update']);
        });
    });

    Route::prefix('clipboard')->group(function () {
        Route::get('', [ClipboardController::class, 'list']);
        Route::post('', [ClipboardController::class, 'store']);
        Route::post('image', [ClipboardController::class, 'storeImage']);
        Route::get('{clipboard}/image', [ClipboardController::class, 'getImage']);
        Route::delete('{clipboard}', [ClipboardController::class, 'destroy']);
    });

    Route::prefix('time-tracking')->group(function () {
        Route::apiResource('categories', TimeTrackingCategoryController::class);
        Route::apiResource('projects', TimeTrackingProjectController::class);

        Route::prefix('entries')->name('entries.')->group(function () {
            Route::post('start', [TimeTrackingEntryController::class, 'start']);
            Route::post('{entry}/stop', [TimeTrackingEntryController::class, 'stop']);
            Route::get('statistics', [TimeTrackingEntryController::class, 'statistics']);

            Route::apiResource('/', TimeTrackingEntryController::class)->parameters(['' => 'entry']);
        });
    });

    Route::prefix('export')->group(function () {
        Route::post('', [ExportController::class, 'store']);
        Route::get('status', [ExportController::class, 'status']);
        Route::get('{id}/download', [ExportController::class, 'download']);
    });
});
