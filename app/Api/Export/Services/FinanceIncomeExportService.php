<?php

namespace App\Api\Export\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Finances\Models\Budget;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\Users\Models\User;
use App\Shared\Helpers\CsvHelper;

class FinanceIncomeExportService
{
    public function export(User $user, string $path): void
    {
        $incomes = Budget::forUser($user->id)->where('type', 'income')->get();

        CsvHelper::write($path, ['Title', 'Value'], $incomes->map(fn ($income) => [
            $income->title,
            $income->value,
        ]));
    }
}
