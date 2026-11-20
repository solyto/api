<?php

namespace Database\Seeders;

use App\Api\Calendars\Models\ExternalCalendar;
use App\Api\Calendars\Models\ExternalCalendarAccount;
use App\Api\Calendars\Services\CalendarService;
use App\Api\CheckIn\Services\CheckInService;
use App\Api\CheckIn\Services\CheckInTrackerService;
use App\Api\Clipboard\Models\Clipboard;
use App\Api\Contacts\Services\ContactService;
use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestComment;
use App\Api\DevRequests\Models\DevRequestVote;
use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\BudgetValue;
use App\Api\Finances\Models\WealthField;
use App\Api\Finances\Models\WealthValue;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryBookGenre;
use App\Api\Libraries\Models\LibraryGame;
use App\Api\Libraries\Models\LibraryGameGenre;
use App\Api\Libraries\Models\LibraryLink;
use App\Api\Libraries\Models\LibraryLinkCategory;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryMovieGenre;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Models\LibraryMusicGenre;
use App\Api\Libraries\Models\LibraryPlant;
use App\Api\Libraries\Models\LibraryQuote;
use App\Api\Libraries\Models\LibraryRecipe;
use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Shortcuts\Models\Shortcut;
use App\Api\Tags\Models\Tag;
use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\TimeTracking\Models\TimeTrackingCategory;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Models\TodoCategory;
use App\Api\Todos\Models\TodoSubtask;
use App\Api\Todos\Models\TodoWorkspace;
use App\Api\Users\Models\Friend;
use App\Api\Users\Models\FriendRequest;
use App\Api\Users\Models\User;
use App\Api\Users\Models\UserNotificationSettings;
use App\Api\Users\Services\UserService;
use App\Dav\DTOs\EventDTO;
use App\Plugins\Models\UserPlugin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Seeder;

// Local development only: fake accounts, so a stray export or dump never contains real data.
class DatabaseSeeder extends Seeder
{
    public const string TEST_EMAIL = 'test@solyto.local';
    public const string FRIEND_EMAIL = 'friend@solyto.local';
    public const string PENDING_EMAIL = 'pending@solyto.local';

    // Real public feeds, so the hourly sync has something to fetch.
    private const array FEED_URLS = [
        'Hacker News' => 'https://hnrss.org/frontpage',
        'BBC News' => 'https://feeds.bbci.co.uk/news/rss.xml',
        'NASA Breaking News' => 'https://www.nasa.gov/news-release/feed/',
    ];

    public function run(UserService $users, CalendarService $calendars, ContactService $contacts): void
    {
        // Laravel's --force prompt alone is not enough: this seeder deletes accounts.
        if (app()->environment('production')) {
            throw new \RuntimeException('The local test data seeder must not run in production.');
        }

        $this->deleteTestUsers($users);

        $user = User::factory()->create(['name' => 'Test User', 'email' => self::TEST_EMAIL]);
        $friend = User::factory()->create(['name' => 'Test Friend', 'email' => self::FRIEND_EMAIL]);
        $pending = User::factory()->create(['name' => 'Pending Friend', 'email' => self::PENDING_EMAIL]);

        $tags = Tag::factory()->forUser($user)->count(6)->sequence(
            ['name' => 'urgent'], ['name' => 'home'], ['name' => 'work'],
            ['name' => 'later'], ['name' => 'ideas'], ['name' => 'favourite'],
        )->create();

        $this->seedTodos($user, $tags);
        $this->seedNotes($user, $tags);
        $this->seedLibraries($user, $tags);
        $this->seedFinances($user, $tags);
        $this->seedTimeTracking($user);
        $this->seedSmallModules($user);
        $this->seedCalendars($user, $friend, $calendars);
        $this->seedContacts($user, $contacts);
        $this->seedRemoteModules($user, $calendars);
        $this->seedDevRequests($user, $friend);
        $this->seedFriends($user, $friend, $pending);
    }

    private function deleteTestUsers(UserService $users): void
    {
        foreach (User::whereIn('email', [self::TEST_EMAIL, self::FRIEND_EMAIL, self::PENDING_EMAIL])->get() as $existing) {
            // destroy() only anonymises dev requests (shared data); the seeded ones are removed so reruns don't pile up.
            DevRequest::where('created_by_user_id', $existing->id)->delete();
            $users->destroy($existing);
        }
    }

    private function seedTodos(User $user, Collection $tags): void
    {
        $categories = TodoCategory::factory()->forUser($user)->count(4)->sequence(
            ['title' => 'Work'], ['title' => 'Home'], ['title' => 'Shopping'], ['title' => 'Health'],
        )->create();

        TodoWorkspace::factory()->forUser($user)->notHideable()->create(['title' => 'Everyday'])
            ->categories()->attach($categories->whereIn('title', ['Home', 'Shopping', 'Health'])->pluck('id'));
        TodoWorkspace::factory()->forUser($user)->hideable()->create(['title' => 'Office'])
            ->categories()->attach($categories->firstWhere('title', 'Work')->id);

        $todos = collect([
            ...Todo::factory()->forUser($user)->overdue()->count(3)->create(),
            ...Todo::factory()->forUser($user)->dueToday()->count(3)->create(),
            ...Todo::factory()->forUser($user)->pending()->count(4)->create(['due_at' => fake()->dateTimeBetween('+1 day', '+3 weeks')]),
            ...Todo::factory()->forUser($user)->pending()->noDueDate()->count(2)->create(),
            ...Todo::factory()->forUser($user)->completed()->count(4)->create(['completed_at' => fake()->dateTimeBetween('-2 weeks', 'now')]),
        ]);

        foreach ($todos as $todo) {
            $todo->update(['category_id' => $categories->random()->id]);
            $this->attachSome($todo->tags(), $tags);
        }

        foreach ($todos->random(5) as $todo) {
            TodoSubtask::factory()->forTodo($todo)->count(fake()->numberBetween(2, 4))->create();
        }
    }

    private function seedNotes(User $user, Collection $tags): void
    {
        $work = NoteCategory::factory()->forUser($user)->asRoot()->withSortOrder(0)->withTitle('Work')->create();
        $personal = NoteCategory::factory()->forUser($user)->asRoot()->withSortOrder(1)->withTitle('Personal')->create();
        $meetings = NoteCategory::factory()->forUser($user)->withParent($work)->withSortOrder(0)->withTitle('Meetings')->create();
        $ideas = NoteCategory::factory()->forUser($user)->withParent($personal)->withSortOrder(0)->withTitle('Ideas')->create();

        foreach ([$work, $personal, $meetings, $ideas, null] as $category) {
            $notes = Note::factory()->forUser($user)->count(fake()->numberBetween(2, 4))->create(['category_id' => $category?->id]);
            foreach ($notes as $note) {
                $this->attachSome($note->tags(), $tags);
            }
        }
    }

    private function seedLibraries(User $user, Collection $tags): void
    {
        $bookGenres = LibraryBookGenre::factory()->forUser($user)->count(4)->sequence(
            ['title' => 'Fiction'], ['title' => 'Sci-Fi'], ['title' => 'Biography'], ['title' => 'Mystery'],
        )->create();
        $books = collect([
            ...LibraryBook::factory()->forUser($user)->count(4)->create(['wishlist' => false]),
            ...LibraryBook::factory()->forUser($user)->inProgress()->count(2)->create(['wishlist' => false]),
            ...LibraryBook::factory()->forUser($user)->completed()->highRated()->count(3)->create(['wishlist' => false]),
            ...LibraryBook::factory()->forUser($user)->lent()->count(1)->create(['wishlist' => false]),
            ...LibraryBook::factory()->forUser($user)->wishlist()->count(2)->create(),
        ]);
        foreach ($books as $book) {
            $this->attachSome($book->genres(), $bookGenres);
            $this->attachSome($book->tags(), $tags);
        }

        $gameGenres = LibraryGameGenre::factory()->forUser($user)->count(4)->sequence(
            ['title' => 'RPG'], ['title' => 'Strategy'], ['title' => 'Puzzle'], ['title' => 'Adventure'],
        )->create();
        $games = collect([
            ...LibraryGame::factory()->forUser($user)->inProgress()->count(2)->create(['wishlist' => false]),
            ...LibraryGame::factory()->forUser($user)->completed()->count(3)->create(['wishlist' => false]),
            ...LibraryGame::factory()->forUser($user)->wishlist()->count(2)->create(),
            ...LibraryGame::factory()->forUser($user)->withPlatform('boardgame')->count(2)->create(['wishlist' => false]),
        ]);
        foreach ($games as $game) {
            $this->attachSome($game->genres(), $gameGenres);
            $this->attachSome($game->tags(), $tags);
        }

        $movieGenres = LibraryMovieGenre::factory()->forUser($user)->count(4)->sequence(
            ['title' => 'Drama'], ['title' => 'Comedy'], ['title' => 'Sci-Fi'], ['title' => 'Documentary'],
        )->create();
        $movies = collect([
            ...LibraryMovie::factory()->forUser($user)->movie()->completed()->count(4)->create(['wishlist' => false]),
            ...LibraryMovie::factory()->forUser($user)->movie()->wishlist()->count(2)->create(),
            ...LibraryMovie::factory()->forUser($user)->tvSeries()->inProgress()->count(2)->create(['wishlist' => false]),
            ...LibraryMovie::factory()->forUser($user)->tvSeries()->completed()->count(2)->create(['wishlist' => false]),
        ]);
        foreach ($movies as $movie) {
            $this->attachSome($movie->genres(), $movieGenres);
            $this->attachSome($movie->tags(), $tags);
        }

        $musicGenres = LibraryMusicGenre::factory()->forUser($user)->count(4)->sequence(
            ['title' => 'Rock'], ['title' => 'Jazz'], ['title' => 'Electronic'], ['title' => 'Classical'],
        )->create();
        $music = collect([
            ...LibraryMusic::factory()->forUser($user)->album()->vinyl()->count(4)->create(['wishlist' => false]),
            ...LibraryMusic::factory()->forUser($user)->count(4)->create(['wishlist' => false]),
            ...LibraryMusic::factory()->forUser($user)->wishlist()->count(2)->create(),
        ]);
        foreach ($music as $album) {
            $this->attachSome($album->genres(), $musicGenres);
        }

        LibraryPlant::factory()->forUser($user)->count(5)->create();

        foreach (LibraryQuote::factory()->forUser($user)->count(6)->create() as $quote) {
            $this->attachSome($quote->tags(), $tags);
        }

        LibraryRecipe::factory()->forUser($user)->count(3)->create();
        LibraryRecipe::factory()->forUser($user)->quick()->highRated()->count(2)->create();
        LibraryRecipe::factory()->forUser($user)->timeConsuming()->create();

        $linkCategories = LibraryLinkCategory::factory()->forUser($user)->count(3)->sequence(
            ['title' => 'Learning'], ['title' => 'News'], ['title' => 'Tools'],
        )->create();
        $links = collect([
            ...LibraryLink::factory()->forUser($user)->count(3)->create(),
            ...$linkCategories->flatMap(fn (LibraryLinkCategory $category) => LibraryLink::factory()->forUser($user)->forCategory($category)->count(3)->create()),
        ]);
        foreach ($links as $link) {
            $this->attachSome($link->tags(), $tags);
        }
    }

    private function seedFinances(User $user, Collection $tags): void
    {
        $budgets = collect([
            Budget::factory()->forUser($user)->income()->create(['title' => 'Salary']),
            Budget::factory()->forUser($user)->expense()->create(['title' => 'Rent']),
            Budget::factory()->forUser($user)->expense()->create(['title' => 'Groceries']),
            Budget::factory()->forUser($user)->expense()->create(['title' => 'Entertainment']),
        ]);
        foreach ($budgets as $budget) {
            $this->attachSome($budget->tags(), $tags);
            foreach (range(0, 5) as $monthsBack) {
                BudgetValue::factory()->forBudget($budget)->create([
                    'date' => now()->subMonthsNoOverflow($monthsBack)->startOfMonth(),
                    'value' => round($budget->value * fake()->randomFloat(2, 0.8, 1.2), 2),
                ]);
            }
        }

        foreach (['Savings Account', 'Investments', 'Cash'] as $title) {
            $field = WealthField::factory()->forUser($user)->withTitle($title)->create();
            $value = fake()->randomFloat(2, 1000, 20000);
            foreach (range(11, 0) as $monthsBack) {
                $value = round($value * fake()->randomFloat(3, 0.97, 1.06), 2);
                WealthValue::factory()->forField($field)->create([
                    'date' => now()->subMonthsNoOverflow($monthsBack)->startOfMonth(),
                    'value' => $value,
                ]);
            }
        }
    }

    private function seedTimeTracking(User $user): void
    {
        $categories = TimeTrackingCategory::factory()->forUser($user)->count(3)->sequence(
            ['title' => 'Development'], ['title' => 'Meetings'], ['title' => 'Research'],
        )->create();
        $projects = TimeTrackingProject::factory()->forUser($user)->count(3)->sequence(
            ['title' => 'Website Redesign'], ['title' => 'Mobile App'], ['title' => 'Internal Tools'],
        )->create();

        foreach ($projects as $project) {
            $project->categories()->attach($categories->random(fake()->numberBetween(1, 2))->pluck('id'));
            TimeTrackingEntry::factory()->forUser($user)->forProject($project)->count(8)->create();
            TimeTrackingEntry::factory()->forUser($user)->forProject($project)->today()->create();
        }

        TimeTrackingEntry::factory()->forUser($user)->forProject($projects->first())->running()->create([
            'started_at' => now()->subMinutes(42),
        ]);
    }

    private function seedCheckIn(User $user): void
    {
        $checkIns = app(CheckInService::class);
        $trackers = app(CheckInTrackerService::class);

        $meditated = $trackers->create($user, ['name' => 'Meditated', 'type' => 'yes_no', 'icon' => 'flower-2']);
        $glasses = $trackers->create($user, ['name' => 'Glasses of water', 'type' => 'count', 'icon' => 'glass-water']);
        $trackers->setGoal($meditated, ['period' => 'week', 'comparison' => 'at_least', 'target' => 5]);
        $trackers->addAlert($glasses, ['time' => '18:00', 'target' => 5]);

        foreach (range(29, 0) as $daysAgo) {
            $date = now()->subDays($daysAgo)->toDateString();

            $checkIns->saveDay($user, $date, [
                'mood' => fake()->numberBetween(2, 5),
                'sleep' => fake()->numberBetween(1, 5),
                'water' => fake()->numberBetween(2, 5),
                'food_quality' => fake()->numberBetween(2, 5),
                'sports' => fake()->optional(0.4)->numberBetween(1, 5),
            ]);
            $checkIns->setValue($user, $meditated, $date, fake()->boolean(70) ? 1 : null);
            $checkIns->setValue($user, $glasses, $date, fake()->numberBetween(2, 9));
        }
    }

    private function seedSmallModules(User $user): void
    {
        $this->seedCheckIn($user);

        Clipboard::factory()->forUser($user)->count(2)->create();
        Clipboard::factory()->forUser($user)->withUrl()->create();
        Clipboard::factory()->forUser($user)->asCode()->create();

        Shortcut::factory()->forUser($user)->count(3)->sequence(
            ['title' => 'DuckDuckGo', 'url' => 'https://duckduckgo.com', 'order' => 0],
            ['title' => 'Wikipedia', 'url' => 'https://www.wikipedia.org', 'order' => 1],
            ['title' => 'OpenStreetMap', 'url' => 'https://www.openstreetmap.org', 'order' => 2],
        )->create();

        UserNotificationSettings::factory()->forUser($user)->create();
    }

    // The external calendar account, Telegram chat and AI key are fake: their syncs and deliveries fail by design.
    private function seedRemoteModules(User $user, CalendarService $calendars): void
    {
        foreach (self::FEED_URLS as $title => $url) {
            $feed = Feed::where('url', $url)->first() ?? Feed::factory()->withTitle($title)->withUrl($url)->create(['created_by' => $user->id]);
            FeedSubscription::factory()->forUserAndFeed($user, $feed)->withTitle($title)->create();
        }

        $account = ExternalCalendarAccount::factory()->forUser($user)->create([
            'server_url' => 'https://dav.example.invalid/',
        ]);
        $calendar = $calendars->create($user, ['name' => 'Remote (fake)', 'color' => '#7C3AED']);
        ExternalCalendar::factory()->forAccount($account)->create([
            'remote_url' => 'https://dav.example.invalid/calendars/test/remote/',
            'calendar_id' => $calendar->calendarId,
            'instance_id' => $calendar->instanceId,
        ]);
        $calendars->markExternal($user, $calendar);

        TelegramBotConnection::factory()->forUser($user)->confirmed()->withAllAlerts()->create();

        UserPlugin::factory()->forUser($user)->create();
    }

    private function seedDevRequests(User $user, User $friend): void
    {
        $requests = collect([
            DevRequest::factory()->feature()->pending()->create(['created_by_user_id' => $user->id]),
            DevRequest::factory()->feature()->inProgress()->highPriority()->create(['created_by_user_id' => $user->id]),
            DevRequest::factory()->bug()->completed()->create(['created_by_user_id' => $user->id]),
            DevRequest::factory()->bug()->pending()->withUrl()->create(['created_by_user_id' => $friend->id]),
            DevRequest::factory()->feature()->waitingForDeployment()->create(['created_by_user_id' => $friend->id]),
        ]);

        foreach ($requests as $request) {
            DevRequestComment::factory()->forDevRequestAndUser($request, $friend)->create();
            DevRequestComment::factory()->forDevRequestAndUser($request, $user)->short()->create();
            DevRequestVote::factory()->forDevRequestAndUser($request, $user)->upvote()->create();
            DevRequestVote::factory()->forDevRequestAndUser($request, $friend)->create();
        }
    }

    private function seedCalendars(User $user, User $friend, CalendarService $calendars): void
    {
        $user->load('settings');
        $timezone = $user->settings->timezone;
        $default = $calendars->getByName($user, 'My Calendar');
        $work = $calendars->create($user, ['name' => 'Work', 'color' => '#16A34A']);
        $start = CarbonImmutable::now($timezone)->startOfMonth();

        foreach (range(1, 20) as $i) {
            $day = $start->addDays(fake()->numberBetween(0, 58));
            $calendar = fake()->boolean() ? $default : $work;

            if (fake()->boolean(25)) {
                $data = ['start_date' => $day->toDateString(), 'end_date' => $day->addDays(fake()->numberBetween(0, 2))->toDateString(), 'is_all_day' => true];
            } else {
                $from = $day->setTime(fake()->numberBetween(8, 18), fake()->randomElement([0, 15, 30, 45]));
                $data = ['start_date' => $from->format('Y-m-d H:i'), 'end_date' => $from->addMinutes(fake()->randomElement([30, 60, 90, 120]))->format('Y-m-d H:i'), 'is_all_day' => false];
            }

            $calendars->createEvent($user, $calendar, EventDTO::fromRequest([
                'title' => fake()->sentence(3),
                'description' => fake()->optional(0.5)->sentence(),
                'location' => fake()->optional(0.4)->city(),
                ...$data,
            ], $calendar, $user));
        }

        $standup = $start->next(CarbonImmutable::MONDAY)->setTime(9, 30);
        $calendars->createEvent($user, $work, EventDTO::fromRequest([
            'title' => 'Weekly stand-up',
            'start_date' => $standup->format('Y-m-d H:i'),
            'end_date' => $standup->addMinutes(15)->format('Y-m-d H:i'),
            'is_all_day' => false,
            'recurrence_rule' => 'FREQ=WEEKLY',
        ], $work, $user));

        $shared = $calendars->create($user, ['name' => 'Shared with friend', 'color' => '#EA580C']);
        $party = $start->addDays(fake()->numberBetween(10, 40))->setTime(19, 0);
        $calendars->createEvent($user, $shared, EventDTO::fromRequest([
            'title' => 'Dinner together',
            'start_date' => $party->format('Y-m-d H:i'),
            'end_date' => $party->addHours(3)->format('Y-m-d H:i'),
            'is_all_day' => false,
        ], $shared, $user));
        $calendars->share($shared, $user, $friend);
        $calendars->acceptInvite($friend, $calendars->listInvites($friend)->first()->shareToken);
    }

    private function seedContacts(User $user, ContactService $contacts): void
    {
        $addressBook = $contacts->listAddressBooks($user)[0];

        foreach (range(1, 15) as $i) {
            $contacts->createContact($user, $addressBook, [
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'email' => fake()->boolean(70) ? json_encode([['value' => fake()->safeEmail(), 'type' => 'INTERNET']]) : null,
                'phone' => fake()->boolean(60) ? json_encode([['value' => fake()->phoneNumber(), 'type' => 'CELL']]) : null,
                'organization' => fake()->optional(0.4)->company(),
                'street' => fake()->optional(0.4)->streetAddress(),
                'city' => fake()->optional(0.4)->city(),
                'postal_code' => fake()->optional(0.4)->postcode(),
                'country' => fake()->optional(0.4)->country(),
                'note' => fake()->optional(0.3)->sentence(),
            ]);
        }
    }

    private function seedFriends(User $user, User $friend, User $pending): void
    {
        FriendRequest::factory()->forUsers($friend, $user)->accepted()->create();
        Friend::factory()->forUsers($friend, $user)->create();
        FriendRequest::factory()->forUsers($pending, $user)->pending()->create();
    }

    private function attachSome(BelongsToMany $relation, Collection $related): void
    {
        $relation->attach($related->random(fake()->numberBetween(0, 2))->pluck('id'));
    }
}
