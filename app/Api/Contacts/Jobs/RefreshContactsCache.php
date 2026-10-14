<?php

namespace App\Api\Contacts\Jobs;

use App\Api\Contacts\Services\ContactService;
use App\Api\Users\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class RefreshContactsCache implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public function __construct(
        public string $userId,
    ) {}

    public function handle(ContactService $service): void
    {
        $user = User::findOrFail($this->userId);

        Log::info('Refreshing contacts cache for user ' . $user->id . ' after invalidation');

        $service->listAddressBooks($user);
        $service->listContacts($user);
    }
}
