<?php

namespace App\Api\Export\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Users\Models\User;
use App\Shared\Helpers\CsvHelper;

class CheckInExportService
{
    public const string OWN_TRACKERS_FILE = 'check_in_own_trackers.csv';

    public function export(User $user, string $path): void
    {
        $checkIns = CheckIn::forUser($user->id)->orderBy('date')->get();

        CsvHelper::write($path, [
            'Date', 'Mood', 'Water', 'Sports', 'Sleep', 'Dreams',
            'Work', 'Food Quality', 'Food Amount', 'Menstruation', 'Alcohol', 'Smoking', 'Social Life',
        ], $checkIns->map(fn ($checkIn) => [
            $checkIn->date->format('Y-m-d'),
            $checkIn->mood,
            $checkIn->water,
            $checkIn->sports,
            $checkIn->sleep,
            $checkIn->dreams,
            $checkIn->work,
            $checkIn->food_quality,
            $checkIn->food_amount,
            $checkIn->menstruation,
            $checkIn->alcohol,
            $checkIn->smoking,
            $checkIn->social_life,
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
