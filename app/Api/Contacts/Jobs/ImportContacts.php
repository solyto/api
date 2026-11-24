<?php

namespace App\Api\Contacts\Jobs;

use App\Api\Users\Models\User;
use App\Dav\DTOs\AddressBookImportDTO;
use App\Dav\Services\DavService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class ImportContacts implements ShouldQueue
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
        $state = $dav->import()->addressBooks()->getState($user->id);
        $start = time();

        // The state is shared with the file import, and a job that waited in the queue too long may find a newer import's state.
        while ($this->ownsState($state) && time() - $start < 1800) {
            if ($state->stage === 'address-books') {
                $dav->import()->addressBooks()->importAddressBooks($user, $state);
            } else if ($state->stage === 'contacts') {
                $dav->import()->addressBooks()->importContacts($user, $state);
            }

            $dav->import()->addressBooks()->clearCache($user);
            $state = $dav->import()->addressBooks()->getState($user->id);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->jobId !== null) {
            app(DavService::class)->import()->addressBooks()->fail($this->userId, $this->jobId);
        }
    }

    private function ownsState(?AddressBookImportDTO $state): bool
    {
        return $state !== null
            && $state->source === 'carddav'
            && ($this->jobId === null || $state->jobId === $this->jobId)
            && in_array($state->stage, ['address-books', 'contacts'], true);
    }
}
