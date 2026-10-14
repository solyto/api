<?php

namespace App\Api\Calendars\Jobs;

use App\Api\Users\Models\User;
use App\Dav\Services\DavService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ImportCalendars implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public function __construct(
        public string $userId,
    ) {}

    public function handle(DavService $dav): void
    {
        $user = User::findOrFail($this->userId);
        $state = $dav->import()->calendars()->getState($user->id);
        $start = time();

        if (!$state) {
            return;
        }

        while ($state->stage !== 'finished' && time() - $start < 1800) {
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
}
