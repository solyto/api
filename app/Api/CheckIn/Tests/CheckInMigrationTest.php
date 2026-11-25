<?php

use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\CheckIn\Services\CheckInTrackerService;
use App\Api\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Users made by the factory already get trackers through the observer;
// this turns one back into a user from before the tracker tables.
function legacyCheckInUser(?array $checkInSettings = null): User
{
    $user = makeUser();
    DB::table('check_in_trackers')->where('user_id', $user->id)->delete();
    DB::table('user_settings')->where('user_id', $user->id)->update([
        'check_in_settings' => $checkInSettings === null ? null : json_encode($checkInSettings),
    ]);

    return $user;
}

function legacyCheckInRow(User $user, string $date, array $values): void
{
    DB::table('check_in')->insert([
        'user_id' => $user->id,
        'date' => $date,
        'created_at' => now(),
        'updated_at' => now(),
        ...$values,
    ]);
}

function checkInTableDropMigration(): object
{
    return require database_path('migrations/2026_11_25_000002_drop_check_in_table.php');
}

// The old table is gone after all migrations; these tests need it as it was before the upgrade.
beforeEach(function () {
    checkInTableDropMigration()->down();
});

function runCheckInMigration(): void
{
    (require database_path('migrations/2026_11_19_000005_migrate_check_in_to_trackers.php'))->up();
}

function migratedValues(User $user): array
{
    return CheckInValue::query()
        ->join('check_in_trackers', 'check_in_trackers.id', '=', 'check_in_values.check_in_tracker_id')
        ->where('check_in_trackers.user_id', $user->id)
        ->orderBy('check_in_values.date')
        ->orderBy('check_in_trackers.key')
        ->get(['check_in_trackers.key', 'check_in_values.date', 'check_in_values.value'])
        ->map(fn ($row) => [$row->key, substr((string) $row->date, 0, 10), (int) $row->value])
        ->all();
}

describe('Check-in data migration', function () {
    it('creates the twelve built-in trackers in the old order with the stored on/off state', function () {
        $user = legacyCheckInUser(['enabled_trackers' => ['water', 'mood', 'social_life'], 'selected_sports' => ['yoga', 'bike', 'mountain', 'footprints', 'dumbbell']]);

        runCheckInMigration();

        $trackers = CheckInTracker::forUser($user->id)->orderBy('position')->get();
        expect($trackers->pluck('key')->all())->toBe([
            'mood', 'sleep', 'dreams', 'food_quality', 'food_amount', 'water',
            'sports', 'work', 'menstruation', 'alcohol', 'smoking', 'social_life',
        ])
            ->and($trackers->where('enabled', true)->pluck('key')->values()->all())->toBe(['mood', 'water', 'social_life'])
            ->and($trackers->firstWhere('key', 'sports')->type->value)->toBe('sport')
            ->and($trackers->firstWhere('key', 'sports')->scored)->toBeFalse()
            ->and($trackers->firstWhere('key', 'mood')->scored)->toBeTrue()
            ->and($trackers->whereNotNull('name')->count())->toBe(0);
    });

    it('enables the old default trackers when the user never changed the settings', function () {
        $user = legacyCheckInUser();

        runCheckInMigration();

        expect(CheckInTracker::forUser($user->id)->where('enabled', true)->orderBy('position')->pluck('key')->all())
            ->toBe(['mood', 'sleep', 'dreams', 'food_quality', 'food_amount', 'water', 'sports', 'work']);
    });

    it('copies every stored value, including zeros and sports, and nothing else', function () {
        $user = legacyCheckInUser();
        legacyCheckInRow($user, '2026-10-01', ['mood' => 4, 'water' => 0, 'sports' => 6]);
        legacyCheckInRow($user, '2026-10-02', ['social_life' => 2, 'menstruation' => 5, 'alcohol' => 1, 'smoking' => 5]);
        legacyCheckInRow($user, '2026-10-03', []);

        runCheckInMigration();

        expect(migratedValues($user))->toBe([
            ['mood', '2026-10-01', 4],
            ['sports', '2026-10-01', 6],
            ['water', '2026-10-01', 0],
            ['alcohol', '2026-10-02', 1],
            ['menstruation', '2026-10-02', 5],
            ['smoking', '2026-10-02', 5],
            ['social_life', '2026-10-02', 2],
        ]);
    });

    it('keeps users apart', function () {
        $first = legacyCheckInUser();
        $second = legacyCheckInUser();
        legacyCheckInRow($first, '2026-10-01', ['mood' => 1]);
        legacyCheckInRow($second, '2026-10-01', ['mood' => 5]);

        runCheckInMigration();

        expect(migratedValues($first))->toBe([['mood', '2026-10-01', 1]])
            ->and(migratedValues($second))->toBe([['mood', '2026-10-01', 5]]);
    });

    it('leaves the old table untouched', function () {
        $user = legacyCheckInUser();
        legacyCheckInRow($user, '2026-10-01', ['mood' => 4]);
        $before = DB::table('check_in')->get()->toArray();

        runCheckInMigration();

        expect(DB::table('check_in')->get()->toArray())->toEqual($before);
    });

    it('copies the history of users whose built-in trackers the app already created', function () {
        $user = legacyCheckInUser(['enabled_trackers' => ['water', 'alcohol']]);
        app(CheckInTrackerService::class)->provisionBuiltIns($user);
        legacyCheckInRow($user, '2026-10-01', ['mood' => 4, 'alcohol' => 1]);

        runCheckInMigration();

        expect(CheckInTracker::forUser($user->id)->count())->toBe(12)
            ->and(CheckInTracker::forUser($user->id)->where('enabled', true)->orderBy('position')->pluck('key')->all())->toBe(['water', 'alcohol'])
            ->and(migratedValues($user))->toBe([['alcohol', '2026-10-01', 1], ['mood', '2026-10-01', 4]]);
    });

    it('migrates users in several chunks', function () {
        $users = collect(range(1, 120))->map(function (int $i) {
            $user = legacyCheckInUser();
            legacyCheckInRow($user, '2026-10-01', ['mood' => $i % 5 + 1]);

            return [$user, $i % 5 + 1];
        });

        runCheckInMigration();

        $users->each(fn (array $pair) => expect(migratedValues($pair[0]))->toBe([['mood', '2026-10-01', $pair[1]]]));
    });

    it('skips users who already have values, so running it again changes nothing', function () {
        $user = legacyCheckInUser();
        legacyCheckInRow($user, '2026-10-01', ['mood' => 4]);

        runCheckInMigration();
        runCheckInMigration();

        expect(CheckInTracker::forUser($user->id)->count())->toBe(12)
            ->and(migratedValues($user))->toBe([['mood', '2026-10-01', 4]]);
    });

    it('migrates every user who has no trackers yet', function () {
        $users = collect(range(1, 3))->map(fn () => legacyCheckInUser());
        DB::table('check_in_trackers')->delete();

        runCheckInMigration();

        expect(CheckInTracker::count())->toBe(User::count() * 12);
        $users->each(fn (User $user) => expect(CheckInTracker::forUser($user->id)->count())->toBe(12));
    });
});

describe('Dropping the old check-in table', function () {
    it('drops the table and keeps only the chosen sports in the settings', function () {
        $user = legacyCheckInUser(['enabled_trackers' => ['mood'], 'selected_sports' => ['yoga', 'bike', 'mountain', 'footprints', 'dumbbell']]);
        $untouched = legacyCheckInUser(['selected_sports' => ['bike', 'yoga', 'mountain', 'footprints', 'dumbbell']]);
        $empty = legacyCheckInUser();

        checkInTableDropMigration()->up();

        expect(Schema::hasTable('check_in'))->toBeFalse()
            ->and(json_decode(DB::table('user_settings')->where('user_id', $user->id)->value('check_in_settings'), true))
            ->toBe(['selected_sports' => ['yoga', 'bike', 'mountain', 'footprints', 'dumbbell']])
            ->and(json_decode(DB::table('user_settings')->where('user_id', $untouched->id)->value('check_in_settings'), true))
            ->toBe(['selected_sports' => ['bike', 'yoga', 'mountain', 'footprints', 'dumbbell']])
            ->and(DB::table('user_settings')->where('user_id', $empty->id)->value('check_in_settings'))->toBeNull();
    });
});
