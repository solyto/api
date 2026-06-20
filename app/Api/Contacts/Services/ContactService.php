<?php

namespace App\Api\Contacts\Services;

use App\Api\Users\Models\User;
use App\Dav\DTOs\AddressBookDTO;
use App\Dav\DTOs\ContactDTO;
use App\Dav\Services\DavService;
use App\Shared\Services\Images\ImageTransformationService;
use App\Shared\Services\UserCacheService;
use Illuminate\Http\UploadedFile;

class ContactService
{
    private const string CACHE_KEY_ADDRESS_BOOKS = 'address_books';
    private const string CACHE_KEY_CONTACTS = 'contacts';
    private const int CACHE_TTL = 86400;
    public const int PHOTO_MAX_BYTES = 200 * 1024;

    public function __construct(
        private readonly DavService $dav,
        private readonly UserCacheService $cache,
        private readonly ImageTransformationService $imageTransformation
    ) {}

    public function listAddressBooks(User $user): array
    {
        return $this->cache->remember([self::CACHE_KEY_ADDRESS_BOOKS, $user->id], self::CACHE_TTL,
            fn() => $this->dav->addressBooks()->list($user));
    }

    public function getAddressBook(User $user, int $id): ?AddressBookDTO
    {
        return $this->dav->addressBooks()->get($user, (string) $id);
    }

    public function getAddressBookByName(User $user, string $name): ?AddressBookDTO
    {
        return $this->dav->addressBooks()->getByName($user, $name);
    }

    public function createAddressBook(User $user, array $data): AddressBookDTO
    {
        $addressBook = $this->dav->addressBooks()->create($user, AddressBookDTO::fromRequest($data));
        $this->cache->forget([self::CACHE_KEY_ADDRESS_BOOKS, $user->id]);
        return $addressBook;
    }

    public function updateAddressBook(User $user, AddressBookDTO $addressBook): void
    {
        $this->dav->addressBooks()->update($user, $addressBook);
        $this->cache->forget([self::CACHE_KEY_ADDRESS_BOOKS, $user->id]);
    }

    public function destroyAddressBook(User $user, AddressBookDTO $addressBook): void
    {
        $this->dav->addressBooks()->delete($addressBook->id);
        $this->cache->forget([self::CACHE_KEY_ADDRESS_BOOKS, $user->id]);
        $this->cache->forget([self::CACHE_KEY_CONTACTS, $user->id, $addressBook->id]);
    }

    public function listContacts(User $user): array
    {
        $addressBooks = $this->listAddressBooks($user);
        $contacts = [];
        foreach ($addressBooks as $addressBook) {
            $addressBookContacts = $this->cache->remember(
                [self::CACHE_KEY_CONTACTS, $user->id, $addressBook->id],
                self::CACHE_TTL,
                fn() => $this->dav->addressBooks()->contacts()->list($addressBook)
            );
            $contacts = array_merge($contacts, $addressBookContacts);
            unset($addressBookContacts);
        }
        gc_collect_cycles();
        return $contacts;
    }

    public function getContact(AddressBookDTO $addressBook, string $uri): ?ContactDTO
    {
        return $this->dav->addressBooks()->contacts()->get($addressBook, $uri);
    }

    public function getContactPhotos(User $user, array $contacts): array
    {
        $photos = [];
        foreach ($contacts as $contactData) {
            $addressBook = $this->getAddressBook($user, $contactData['address_book_id']);
            if (!$addressBook) {
                continue;
            }
            $contact = $this->getContact($addressBook, $contactData['uri']);
            if (!$contact || empty($contact->photo)) {
                continue;
            }
            $photos[$contactData['uri']] = $this->convertPhotoString($contact->photo);
        }
        return $photos;
    }

    public function createContact(User $user, AddressBookDTO $addressBook, array $data): ContactDTO
    {
        $contact = $this->dav->addressBooks()->contacts()->create($addressBook, ContactDTO::fromRequest($data, $addressBook));
        $this->cache->forget([self::CACHE_KEY_CONTACTS, $user->id, $addressBook->id]);
        return $contact;
    }

    public function updateContact(User $user, AddressBookDTO $addressBook, ContactDTO $contact, array $data): ?ContactDTO
    {
        $contact->updateFromRequest($data);
        $updated = $this->dav->addressBooks()->contacts()->update($addressBook, $contact);
        if ($updated !== null) {
            $this->cache->forget([self::CACHE_KEY_CONTACTS, $user->id, $addressBook->id]);
        }
        return $updated;
    }

    public function destroyContact(User $user, AddressBookDTO $addressBook, ContactDTO $contact): bool
    {
        $success = $this->dav->addressBooks()->contacts()->delete($addressBook, $contact);
        if ($success) {
            $this->cache->forget([self::CACHE_KEY_CONTACTS, $user->id, $addressBook->id]);
        }
        return $success;
    }

    public function updateContactPhoto(User $user, AddressBookDTO $addressBook, ContactDTO $contact, UploadedFile $photo): ?ContactDTO
    {
        $this->imageTransformation->scaleToFileSize($photo->getRealPath(), self::PHOTO_MAX_BYTES);
        $contact->updatePhoto($photo);
        $updated = $this->dav->addressBooks()->contacts()->update($addressBook, $contact);
        if ($updated !== null) {
            $this->cache->forget([self::CACHE_KEY_CONTACTS, $user->id, $addressBook->id]);
        }
        return $updated;
    }

    public function removeContactPhoto(User $user, AddressBookDTO $addressBook, ContactDTO $contact): ?ContactDTO
    {
        $contact->photo = null;
        $updated = $this->dav->addressBooks()->contacts()->update($addressBook, $contact);
        if ($updated !== null) {
            $this->cache->forget([self::CACHE_KEY_CONTACTS, $user->id, $addressBook->id]);
        }
        return $updated;
    }

    private function convertPhotoString(?string $photo): ?string
    {
        if (empty($photo)) {
            return null;
        }
        $parts = explode(':', $photo, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$meta, $base64Data] = $parts;
        $base64Data = preg_replace('/\s+/', '', $base64Data);
        $type = 'image/jpeg';
        if (preg_match('/TYPE=([A-Z0-9]+)/i', $meta, $matches)) {
            $type = 'image/' . strtolower($matches[1]);
        }
        return 'data:' . $type . ';base64,' . $base64Data;
    }
}
