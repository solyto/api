<?php

namespace App\Api\Calendars\Jobs;

use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarImportDTO;
use App\Dav\Services\DavService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ImportCalendars implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    // $jobId is null only for jobs queued before it was added.
    public function __construct(
        public string $userId,
        public ?string $jobId = null,
    ) {}

    public function handle(DavService $dav): void
    {
        $user = User::findOrFail($this->userId);
        $state = $dav->import()->calendars()->getState($user->id);
        $start = time();

        // The state is shared with the file import, and a job that waited in the queue too long may find a newer import's state.
        while ($this->ownsState($state) && time() - $start < 1800) {
            if ($state->stage === 'calendars') {
                Log::info('Importing Calendars');
                $dav->import()->calendars()->importCalendars($user, $state);
            } else if ($state->stage === 'events') {
                Log::info('Importing events');
                $dav->import()->calendars()->importEvents($user, $state);
            }

            $dav->import()->calendars()->clearCache($user);
            $state = $dav->import()->calendars()->getState($user->id);
        }
    }

    private function ownsState(?CalendarImportDTO $state): bool
    {
        return $state !== null
            && $state->source === 'caldav'
            && ($this->jobId === null || $state->jobId === $this->jobId)
            && in_array($state->stage, ['calendars', 'events'], true);
    }
}
