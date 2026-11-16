<?php

namespace App\Api\Clipboard\Jobs;

use App\Api\Clipboard\Models\Clipboard;
use App\Api\Clipboard\Services\ClipboardFileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class DeleteOverdueClipboardEntries implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public function __construct()
    {
        //
    }

    public function handle(ClipboardFileService $fileService): void
    {
        $overdueEntries = Clipboard::where('created_at', '<', now()->subDay())->get();

        foreach ($overdueEntries as $entry) {
            if ($entry->file_path) {
                $fileService->delete($entry->file_path);
            }
        }

        Clipboard::where('created_at', '<', now()->subDay())->delete();
    }
}
