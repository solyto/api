<?php

namespace App\Api\Export\Services;

use App\Api\Users\Models\User;
use App\Dav\Services\DavService;

class ContactExportService
{
    public function __construct(
        private readonly DavService $davService
    ) {}

    public function export(User $user, string $path): void
    {
        $addressBooks = $this->davService->addressBooks()->list($user);

        $vcards = [];

        foreach ($addressBooks as $book) {
            foreach ($this->davService->addressBooks()->contacts()->list($book) as $contact) {
                $vcards[] = $contact->toVCard();
            }
        }

        file_put_contents($path, implode("\r\n", $vcards));
    }
}
