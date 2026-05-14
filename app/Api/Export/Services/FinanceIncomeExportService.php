<?php

namespace App\Api\Export\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Finances\Models\Budget;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\Users\Models\User;

class FinanceIncomeExportService
{
    public function export(User $user, string $path): void
    {
        $incomes = Budget::forUser($user->id)->where('type', 'income')->get();

        $handle = fopen($path, 'w');
        fputcsv($handle, ['Title', 'Value']);

        foreach ($incomes as $income) {
            fputcsv($handle, [
                $income->title,
                $income->value,
            ]);
        }

        fclose($handle);
    }
}
