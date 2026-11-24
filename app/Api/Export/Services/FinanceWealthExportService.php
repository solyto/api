<?php

namespace App\Api\Export\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\WealthField;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\Users\Models\User;
use App\Shared\Helpers\CsvHelper;
use Illuminate\Support\Collection;

class FinanceWealthExportService
{
    public function export(User $user, string $path): void
    {
        $fields = WealthField::forUser($user->id)->with('values')->get();

        CsvHelper::write($path, ['Field', 'Date', 'Value'], $fields->flatMap(fn (WealthField $field) => $this->fieldRows($field)));
    }

    private function fieldRows(WealthField $field): Collection
    {
        return $field->values->map(fn ($value) => [
            $field->title,
            $value->date->format('Y-m-d'),
            $value->value,
        ]);
    }
}
