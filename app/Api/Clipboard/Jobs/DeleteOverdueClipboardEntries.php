<?php

namespace App\Api\Clipboard\Jobs;

use App\Api\Clipboard\Models\Clipboard;
use App\Api\Clipboard\Services\ClipboardImageService;
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

    public function handle(ClipboardImageService $service): void
    {
        $overdueEntries = Clipboard::where('created_at', '<', now()->subDay())->get();

        foreach ($overdueEntries as $entry) {
            if ($entry->type === 'image' && $entry->file_path) {
                $service->delete($entry->file_path);
            }
        }

        Clipboard::where('created_at', '<', now()->subDay())->delete();
    }
}
