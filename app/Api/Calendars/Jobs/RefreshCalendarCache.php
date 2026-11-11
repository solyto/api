<?php

namespace App\Api\Calendars\Jobs;

use App\Api\Calendars\Services\CalendarService;
use App\Api\Users\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One pending refresh per user: a DAV sync writes one object per request,
 * and the caches are already dropped synchronously on each write. The lock
 * is released when the job starts, so a write during a run queues another.
 */
class RefreshCalendarCache implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public int $uniqueFor = 60;

    public function __construct(
        public string $userId,
    ) {}

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(CalendarService $service): void
    {
        $user = User::findOrFail($this->userId);

        $service->list($user);
        $service->listEvents($user, date('Y-m'));
    }
}
