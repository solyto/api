<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Copies every user's built-in trackers, settings and check-in values into the tracker tables.
// The old `check_in` table is left as it is (and kept in sync by the app) so a rollback loses nothing.
// Pure DB code on purpose: it must not change when the app code does.
return new class extends Migration
{
    private const array TRACKERS = [
        'mood', 'sleep', 'dreams', 'food_quality', 'food_amount', 'water',
        'sports', 'work', 'menstruation', 'alcohol', 'smoking', 'social_life',
    ];

    private const array DEFAULT_ENABLED = ['mood', 'sports', 'water', 'sleep', 'dreams', 'work', 'food_quality', 'food_amount'];

    private const int CHUNK = 500;

    public function up(): void
    {
        DB::transaction(function () {
            DB::table('users')
                ->whereNotIn('id', DB::table('check_in_trackers')->select('user_id'))
                ->select('id')
                ->chunkById(self::CHUNK, fn (Collection $users) => $this->migrateUsers($users->pluck('id')->all()));
        });
    }

    public function down(): void
    {
        DB::table('check_in_alerts')->delete();
        DB::table('check_in_goals')->delete();
        DB::table('check_in_values')->delete();
        DB::table('check_in_trackers')->delete();
    }

    /** @param array<int, string> $userIds */
    private function migrateUsers(array $userIds): void
    {
        $now = now();
        $settings = DB::table('user_settings')->whereIn('user_id', $userIds)->pluck('check_in_settings', 'user_id');

        $trackers = [];
        foreach ($userIds as $userId) {
            $enabled = $this->enabledTrackers($settings[$userId] ?? null);

            foreach (self::TRACKERS as $position => $key) {
                $trackers[] = [
                    'user_id' => $userId,
                    'key' => $key,
                    'name' => null,
                    'type' => $key === 'sports' ? 'sport' : 'scale',
                    'icon' => null,
                    'position' => $position,
                    'enabled' => in_array($key, $enabled, true),
                    'scored' => $key !== 'sports',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('check_in_trackers')->insert($trackers);

        $trackerIds = DB::table('check_in_trackers')
            ->whereIn('user_id', $userIds)
            ->get(['id', 'user_id', 'key'])
            ->mapWithKeys(fn ($tracker) => [$tracker->user_id.'|'.$tracker->key => $tracker->id]);

        $checkIns = DB::table('check_in')->whereIn('user_id', $userIds)->get();

        $values = [];
        foreach ($checkIns as $checkIn) {
            foreach (self::TRACKERS as $key) {
                if ($checkIn->$key === null) {
                    continue;
                }

                $values[] = [
                    'check_in_tracker_id' => $trackerIds[$checkIn->user_id.'|'.$key],
                    'date' => substr((string) $checkIn->date, 0, 10),
                    'value' => (int) $checkIn->$key,
                    'created_at' => $checkIn->created_at ?? $now,
                    'updated_at' => $checkIn->updated_at ?? $now,
                ];
            }
        }

        foreach (array_chunk($values, 1000) as $chunk) {
            DB::table('check_in_values')->insert($chunk);
        }

        $this->verify($userIds, $checkIns, $trackerIds);
    }

    /** @return array<int, string> */
    private function enabledTrackers(?string $settings): array
    {
        $decoded = $settings === null ? null : json_decode($settings, true);

        return is_array($decoded['enabled_trackers'] ?? null) ? $decoded['enabled_trackers'] : self::DEFAULT_ENABLED;
    }

    /**
     * @param array<int, string> $userIds
     * @param Collection<int, object> $checkIns
     * @param Collection<string, int> $trackerIds
     */
    private function verify(array $userIds, Collection $checkIns, Collection $trackerIds): void
    {
        $stored = DB::table('check_in_values')
            ->whereIn('check_in_tracker_id', $trackerIds->values())
            ->get(['check_in_tracker_id', 'date', 'value'])
            ->mapWithKeys(fn ($value) => [$value->check_in_tracker_id.'|'.substr((string) $value->date, 0, 10) => (int) $value->value]);

        $expected = 0;
        foreach ($checkIns as $checkIn) {
            foreach (self::TRACKERS as $key) {
                if ($checkIn->$key === null) {
                    continue;
                }

                $expected++;
                $lookup = $trackerIds[$checkIn->user_id.'|'.$key].'|'.substr((string) $checkIn->date, 0, 10);

                if (($stored[$lookup] ?? null) !== (int) $checkIn->$key) {
                    throw new RuntimeException("Check-in migration mismatch for user {$checkIn->user_id}, {$key} on {$checkIn->date}.");
                }
            }
        }

        if ($stored->count() !== $expected) {
            throw new RuntimeException('Check-in migration value count mismatch: expected '.$expected.', stored '.$stored->count().'.');
        }

        if ($trackerIds->count() !== count($userIds) * count(self::TRACKERS)) {
            throw new RuntimeException('Check-in migration tracker count mismatch.');
        }
    }
};
