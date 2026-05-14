<?php

namespace App\Api\Export\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Users\Models\User;

class CheckInExportService
{
    public function export(User $user, string $path): void
    {
        $checkIns = CheckIn::forUser($user->id)->orderBy('date')->get();

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Date', 'Mood', 'Water', 'Sports', 'Sleep', 'Dreams',
            'Work', 'Food Quality', 'Food Amount', 'Menstruation', 'Alcohol', 'Smoking',
        ]);

        foreach ($checkIns as $checkIn) {
            fputcsv($handle, [
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
            ]);
        }

        fclose($handle);
    }
}
