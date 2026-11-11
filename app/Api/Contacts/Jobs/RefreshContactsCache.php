<?php

namespace App\Api\Contacts\Jobs;

use App\Api\Contacts\Services\ContactService;
use App\Api\Users\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * One pending refresh per user: a DAV sync writes one card per request,
 * and the caches are already dropped synchronously on each write. The lock
 * is released when the job starts, so a write during a run queues another.
 */
class RefreshContactsCache implements ShouldQueue, ShouldBeUniqueUntilProcessing
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

    public function handle(ContactService $service): void
    {
        $user = User::findOrFail($this->userId);

        Log::info('Refreshing contacts cache for user ' . $user->id . ' after invalidation');

        $service->listAddressBooks($user);
        $service->listContacts($user);
    }
}
