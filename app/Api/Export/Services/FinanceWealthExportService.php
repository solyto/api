<?php

namespace App\Api\Export\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\WealthField;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\Users\Models\User;

class FinanceWealthExportService
{
    public function export(User $user, string $path): void
    {
        $fields = WealthField::forUser($user->id)->with('values')->get();

        $handle = fopen($path, 'w');
        fputcsv($handle, ['Field', 'Date', 'Value']);

        foreach ($fields as $field) {
            foreach ($field->values as $value) {
                fputcsv($handle, [
                    $field->title,
                    $value->date?->format('Y-m-d'),
                    $value->value,
                ]);
            }
        }

        fclose($handle);
    }
}
