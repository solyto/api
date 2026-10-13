<?php

namespace App\Api\Export\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Users\Models\User;
use App\Shared\Helpers\CsvHelper;

class CheckInExportService
{
    public function export(User $user, string $path): void
    {
        $checkIns = CheckIn::forUser($user->id)->orderBy('date')->get();

        CsvHelper::write($path, [
            'Date', 'Mood', 'Water', 'Sports', 'Sleep', 'Dreams',
            'Work', 'Food Quality', 'Food Amount', 'Menstruation', 'Alcohol', 'Smoking', 'Social Life',
        ], $checkIns->map(fn ($checkIn) => [
            $checkIn->date?->format('Y-m-d'),
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
    }
}
