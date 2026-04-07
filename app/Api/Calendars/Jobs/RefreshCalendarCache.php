<?php

namespace App\Api\Calendars\Jobs;

use App\Api\Calendars\Services\CalendarService;
use App\Api\Users\Models\User;
use App\Dav\Services\DavService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class RefreshCalendarCache implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public function __construct(
        public User $user,
    ) {}

    public function handle(): void
    {
        $service = app(CalendarService::class);

        Log::info('Refreshing calendar cache for user ' . $this->user->id . ' after invalidation');

        $service->list($this->user);
        $service->listEvents($this->user, date('Y-m'));
    }
}
