<?php

namespace App\Api\Export\Services;

use App\Api\CheckIn\DTOs\CheckInDayDTO;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\CheckIn\Services\CheckInService;
use App\Api\Users\Models\User;
use App\Shared\Helpers\CsvHelper;

class CheckInExportService
{
    public const string OWN_TRACKERS_FILE = 'check_in_own_trackers.csv';

    private const array BUILT_IN_COLUMNS = [
        'mood', 'water', 'sports', 'sleep', 'dreams', 'work', 'food_quality', 'food_amount',
        'menstruation', 'alcohol', 'smoking', 'social_life',
    ];

    public function __construct(private readonly CheckInService $checkIns) {}

    public function export(User $user, string $path): void
    {
        CsvHelper::write($path, [
            'Date', 'Mood', 'Water', 'Sports', 'Sleep', 'Dreams',
            'Work', 'Food Quality', 'Food Amount', 'Menstruation', 'Alcohol', 'Smoking', 'Social Life',
        ], $this->checkIns->days($user)->map(fn (CheckInDayDTO $day) => [
            $day->date,
            ...array_map(fn (string $key) => $day->values[$key] ?? null, self::BUILT_IN_COLUMNS),
        ]));

        $this->exportOwnTrackers($user, dirname($path).'/'.self::OWN_TRACKERS_FILE);
    }

    private function exportOwnTrackers(User $user, string $path): void
    {
        $trackers = CheckInTracker::forUser($user->id)->whereNull('key')->get()->keyBy('id');

        if ($trackers->isEmpty()) {
            return;
        }

        $values = CheckInValue::whereIn('check_in_tracker_id', $trackers->keys())->orderBy('date')->get();

        CsvHelper::write($path, ['Date', 'Tracker', 'Type', 'Value'], $values->map(fn (CheckInValue $value) => [
            $value->date->format('Y-m-d'),
            $trackers[$value->check_in_tracker_id]->name,
            $trackers[$value->check_in_tracker_id]->type->value,
            $value->value,
        ]));
    }
}
