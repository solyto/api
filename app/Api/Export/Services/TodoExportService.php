<?php

namespace App\Api\Export\Services;

use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;

class TodoExportService
{
    public function export(User $user, string $path): void
    {
        $todos = Todo::forUser($user->id)->with(['tags', 'subtasks', 'category'])->get();

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Title', 'Description', 'Priority', 'Status', 'Effort', 'Progress',
            'Due Date', 'Completed At', 'Is Completed', 'Category', 'Tags',
            'Subtasks', 'Recurrence Frequency', 'Recurrence Interval', 'Recurrence Ends At',
        ]);

        foreach ($todos as $todo) {
            fputcsv($handle, [
                $todo->title,
                $todo->description,
                $todo->priority,
                $todo->status,
                $todo->effort,
                $todo->progress,
                $todo->due_at?->format('Y-m-d'),
                $todo->completed_at?->format('Y-m-d H:i:s'),
                $todo->is_completed ? 'Yes' : 'No',
                $todo->category?->title,
                $todo->tags->pluck('name')->implode(', '),
                $todo->subtasks->map(fn ($s) => ($s->is_completed ? '[x] ' : '[ ] ').$s->title)->implode('; '),
                $todo->recurrence_frequency,
                $todo->recurrence_interval,
                $todo->recurrence_ends_at?->format('Y-m-d'),
            ]);
        }

        fclose($handle);
    }
}
