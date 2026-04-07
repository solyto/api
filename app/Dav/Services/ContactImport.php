<?php

namespace App\Dav\Services;

use App\Api\Users\Models\User;
use App\Dav\DTOs\AddressBookDTO;
use App\Dav\DTOs\AddressBookImportDTO;
use App\Dav\Exceptions\ImportException;
use App\Dav\Helpers\VCardHelper;
use App\Shared\Services\UserCacheService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ContactImport
{
    private const string CACHE_KEY_ADDRESS_BOOKS = 'address_books';
    private const string CACHE_KEY_CONTACTS = 'contacts';

    private AddressBooks $addressBooks;
    private VCardHelper $helper;

    public function __construct(AddressBooks $addressBooks, private readonly UserCacheService $cache)
    {
        $this->addressBooks = $addressBooks;
        $this->helper = new VCardHelper();
    }

    public function start(User $user, string $url, string $username, string $secret): void
    {
        $dto = new AddressBookImportDTO();
        $dto->jobId = uniqid();
        $dto->userEmail = $user->email;
        $dto->stage = 'started';
        $dto->url = rtrim($url, '/') . '/';
        $dto->username = $username;
        $dto->secret = $secret;

        $this->saveState($user->id, $dto);
        $this->getAvailableAddressBooks($user, $dto);
    }

    public function selectAddressBooks(User $user, AddressBookImportDTO $dto, array $addressBooks): void
    {
        $dto->selectedAddressBooks = [];

        foreach ($dto->addressBooks as $addressBook) {
            foreach ($addressBooks as $ab) {
                if ($ab === $addressBook['display_name']) {
                    $existingAddressBook = $this->addressBooks->getByName($user, $addressBook['display_name']);

                    if ($existingAddressBook) {
                        $i = 1;

                        while ($existingAddressBook) {
                            $tempName = $addressBook['name'] . ' (' . $i . ')';
                            $tempDisplayName = $addressBook['display_name'] . ' (' . $i . ')';
                            $existingAddressBook = $this->addressBooks->getByName($user, $tempDisplayName);
                            $i++;
                        }

                        $addressBook['name'] = $tempName;
                        $addressBook['display_name'] = $tempDisplayName;
                    }

                    $dto->selectedAddressBooks[] = $addressBook;
                }
            }
        }

        $dto->addressBooksCount = count($dto->selectedAddressBooks);
        $dto->stage = 'address-books';
        $this->saveState($user->id, $dto);
    }

    public function importAddressBooks(User $user, AddressBookImportDTO $dto): void
    {
        $dto->stage = 'address-books';
        $this->saveState($user->id, $dto);

        foreach ($dto->selectedAddressBooks as $addressBook) {
            $dto->currentAddressBook = $addressBook['display_name'];
            $this->saveState($user->id, $dto);

            try {
                unset($addressBook['url']);
                echo 'Importing address book ' . $addressBook['display_name'] . "\n";
                $this->addressBooks->create($user, AddressBookDTO::fromRequest($addressBook));
            } catch (\Exception $e) {
                continue;
            }

            $dto->addressBooksDone++;
            $this->saveState($user->id, $dto);
        }

        $dto->stage = 'contacts';
        $this->saveState($user->id, $dto);
    }

    public function importContacts(User $user, AddressBookImportDTO $dto): void
    {
        $dto->stage = 'contacts';
        $this->saveState($user->id, $dto);

        foreach ($dto->selectedAddressBooks as $addressBook) {
            $a = $this->addressBooks->getByName($user, $addressBook['display_name']);

            if (!$a) {
                continue;
            }

            $dto->currentAddressBook = $addressBook['display_name'];
            $dto->contactsCount = 0;
            $dto->contactsDone = 0;
            $this->saveState($user->id, $dto);

            $contacts = $this->getContacts($dto, $addressBook['url']);
            $dto->contactsCount = count($contacts);
            $this->saveState($user->id, $dto);

            foreach ($contacts as $index => $contact) {
                $dto->currentAddressBook = $addressBook['display_name'];
                $this->saveState($user->id, $dto);

                try {
                    $this->addressBooks->contacts()->import($a, $contact);
                } catch (\Exception $e) {
                    continue;
                }

                $dto->contactsDone++;
                $this->saveState($user->id, $dto);

                unset($contacts[$index]);

                if ($dto->contactsDone % 50 === 0) {
                    gc_collect_cycles();
                }
            }
        }

        $dto->stage = 'finished';
        $this->saveState($user->id, $dto);
    }

    public function clearCache(User $user): void
    {
        $this->cache->forget([self::CACHE_KEY_ADDRESS_BOOKS, $user->id]);

        foreach ($this->addressBooks->list($user) as $addressBook) {
            $this->cache->forget([self::CACHE_KEY_CONTACTS, $user->id, $addressBook->id]);
        }
    }

    public function getAvailableAddressBooks(User $user, AddressBookImportDTO $dto): void
    {
        $homeSet = $this->discoverAddressBookHome($dto);
        $books = $this->getAddressBooks($dto, $homeSet);

        $dto->stage = 'select';
        $dto->addressBooks = $books;
        $dto->addressBooksCount = count($books);
        $this->saveState($user->id, $dto);

        if (!$books) {
            throw new ImportException();
        }
    }

    private function discoverAddressBookHome(AddressBookImportDTO $dto): string
    {
        $response = $this->getBasicRequest($dto)->send(
            'PROPFIND',
            $dto->url . 'principals/users/' . $dto->username . '/',
            ['body' => $this->helper->getAddressBookHomeDiscoveryXml()]
        );

        if (!$response->successful()) {
            throw new ImportException();
        }


        $homes = $this->helper->parseAddressBookHomeFromXml($response->body(), $dto->url);
        return $homes[0]['url'] ?? throw new ImportException();
    }

    private function getAddressBooks(AddressBookImportDTO $dto, string $homeSet): array
    {
        $response = $this->getBasicRequest($dto)->send(
            'PROPFIND',
            $homeSet,
            ['body' => VCardHelper::getAddressBooksXml()]
        );

        if (!$response->successful()) {
            return [];
        }

        return $this->helper->parseAddressBooksFromXml($response->body(), $homeSet);
    }

    private function getContacts(AddressBookImportDTO $dto, string $addressBookUrl): array
    {
        $response = $this->getBasicRequest($dto)->send(
            'REPORT',
            $addressBookUrl,
            ['body' => VCardHelper::getContactsXml()]
        );

        if (!$response->successful()) {
            return [];
        }

        return $this->helper->parseContactsFromXml($response->body());
    }

    private function getBasicRequest(AddressBookImportDTO $dto): PendingRequest
    {
        return Http::withBasicAuth($dto->username, $dto->secret)
                   ->withHeaders([
                       'Content-Type' => 'application/xml',
                       'Depth' => '1',
                   ]);
    }

    private function saveState(string $userId, AddressBookImportDTO $dto): void
    {
        Cache::set('contact-import-' . $userId, $dto, now()->addMinutes(60));
    }

    public function getState(string $userId): ?AddressBookImportDTO
    {
        return Cache::get('contact-import-' . $userId);
    }
}
