<?php

namespace App\Api\Contacts\Jobs;

use App\Api\Calendars\Services\CalendarService;
use App\Api\Contacts\Services\ContactService;
use App\Api\Users\Models\User;
use App\Dav\Services\DavService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class RefreshContactsCache implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public function __construct(
        public User $user,
    ) {}

    public function handle(): void
    {
        $service = app(ContactService::class);

        Log::info('Refreshing contacts cache for user ' . $this->user->id . ' after invalidation');

        $service->listAddressBooks($this->user);
        $service->listContacts($this->user);
    }
}
