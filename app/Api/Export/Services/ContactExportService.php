<?php

namespace App\Api\Export\Services;

use App\Api\Users\Models\User;
use App\Dav\DTOs\ContactDTO;
use App\Dav\Services\DavService;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

class ContactExportService
{
    public function __construct(
        private readonly DavService $davService
    ) {}

    // Cards go out as stored (every email, phone, address, type, photo and X- property), written one at a time
    // because photos make them large.
    public function export(User $user, string $path): void
    {
        $file = fopen($path, 'w');

        try {
            foreach ($this->davService->addressBooks()->list($user) as $book) {
                foreach ($this->davService->addressBooks()->contacts()->listStored($book) as $contact) {
                    fwrite($file, $this->serialize($contact));
                }
            }
        } finally {
            fclose($file);
        }
    }

    private function serialize(ContactDTO $contact): string
    {
        try {
            $vcard = $contact->storedData ? Reader::read($contact->storedData) : null;
            if ($vcard instanceof VCard) {
                return $vcard->serialize();
            }
        } catch (\Throwable) {
            // Unreadable stored card: rebuilt from the contact below.
        }

        return $contact->toVCard()."\r\n";
    }
}
