<?php

use App\Api\Contacts\Jobs\RefreshContactsCache;
use App\Api\Contacts\Services\ContactService;
use App\Dav\DTOs\ContactDTO;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\Queue;

describe('RefreshContactsCache', function () {
    // As in RefreshCalendarCacheTest: Queue::fake keeps the write's refresh
    // from running, and the entry is put back as an earlier run would.
    it('overwrites entries cached before a write', function () {
        Queue::fake();
        $user = davUser();
        $service = app(ContactService::class);
        expect(davRequest($user, 'PUT', 'addressbooks/'.$user->email.'/contacts/early.vcf', davVcard('early', 'Early Contact'), ['Content-Type' => 'text/vcard'])->getStatus())->toBe(201);

        $addressBook = $service->listAddressBooks($user)[0];
        $cache = app(UserCacheService::class);
        $contactsKey = ['contacts', $user->id, $addressBook->id];
        $staleContacts = $service->listContacts($user);
        $staleAddressBook = clone $addressBook;
        $staleAddressBook->displayName = 'Stale';
        $cache->store(['address_books', $user->id], 86400, [$staleAddressBook]);

        expect(davRequest($user, 'PUT', 'addressbooks/'.$user->email.'/contacts/late.vcf', davVcard('late', 'Late Contact'), ['Content-Type' => 'text/vcard'])->getStatus())->toBe(201);
        $cache->store($contactsKey, 86400, $staleContacts);

        (new RefreshContactsCache($user->id))->handle($service);

        expect($cache->get(['address_books', $user->id])[0]->displayName)->toBe($addressBook->displayName)
            ->and(array_map(fn (ContactDTO $contact) => $contact->fullName, $cache->get($contactsKey)))->toEqualCanonicalizing(['Early Contact', 'Late Contact']);
    });
});
