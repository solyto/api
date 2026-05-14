<?php

namespace App\Api\Export\Services;

use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\Users\Models\User;

class TimeTrackingExportService
{
    public function export(User $user, string $path): void
    {
        $entries = TimeTrackingEntry::forUser($user->id)->with('project')->get();

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Description', 'Project', 'Started At', 'Stopped At',
            'Duration (minutes)', 'Has Exact Times',
        ]);

        foreach ($entries as $entry) {
            fputcsv($handle, [
                $entry->description,
                $entry->project?->title,
                $entry->started_at,
                $entry->stopped_at,
                $entry->duration_minutes,
                $entry->has_exact_times ? 'Yes' : 'No',
            ]);
        }

        fclose($handle);
    }
}
