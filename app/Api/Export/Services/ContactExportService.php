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

    // The stored text is written as it is: re-serialising through sabre escapes commas in URI values (a vCard 4
    // PHOTO data URI, URL), and for URL that backslash is never unescaped again.
    private function serialize(ContactDTO $contact): string
    {
        try {
            $vcard = $contact->storedData ? Reader::read($contact->storedData) : null;
            if ($vcard instanceof VCard) {
                $vcard->destroy();

                return preg_replace('/\r\n|\r|\n/', "\r\n", rtrim($contact->storedData, "\r\n"))."\r\n";
            }
        } catch (\Throwable) {
            // Unreadable stored card: rebuilt from the contact below.
        }

        return $contact->toVCard()."\r\n";
    }
}
