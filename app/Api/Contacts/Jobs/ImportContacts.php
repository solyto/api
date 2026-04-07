<?php

namespace App\Api\Contacts\Jobs;

use App\Api\Users\Models\User;
use App\Dav\Services\DavService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ImportContacts implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public function __construct(
        public User $user,
    ) {}

    public function handle(): void
    {
        $dav = app(DavService::class);
        $state = $dav->import()->addressBooks()->getState($this->user->id);
        $start = time();

        if (!$state) {
            return;
        }

        while ($state->stage !== 'finished' && time() - $start < 1800) {
            if ($state->stage === 'address-books') {
                Log::info('Importing Address Books');
                $dav->import()->addressBooks()->importAddressBooks($this->user, $state);
            } else if ($state->stage === 'contacts') {
                Log::info('Importing contacts');
                $dav->import()->addressBooks()->importContacts($this->user, $state);
            }

            $dav->import()->addressBooks()->clearCache($this->user);
            $state = $dav->import()->addressBooks()->getState($this->user->id);
        }
    }
}
