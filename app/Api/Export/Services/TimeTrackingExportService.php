<?php

namespace App\Api\Export\Services;

use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\Users\Models\User;
use App\Shared\Helpers\CsvHelper;

class TimeTrackingExportService
{
    public function export(User $user, string $path): void
    {
        $entries = TimeTrackingEntry::forUser($user->id)->with('project')->get();

        CsvHelper::write($path, [
            'Description', 'Project', 'Started At', 'Stopped At',
            'Duration (minutes)', 'Has Exact Times',
        ], $entries->map(fn ($entry) => [
            $entry->description,
            $entry->project?->title,
            $entry->started_at,
            $entry->stopped_at,
            $entry->duration_minutes,
            $entry->has_exact_times ? 'Yes' : 'No',
        ]));
    }
}
