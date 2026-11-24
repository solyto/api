<?php

namespace App\Dav\DTOs;

class AddressBookImportDTO
{
    public string $jobId;
    public string $userEmail;
    public string $source = 'carddav';
    public string $stage = 'started' | 'select' | 'address-books' | 'contacts' | 'finished';
    public ?string $url = null;
    public ?string $username = null;
    public ?string $secret = null;
    public ?int $targetAddressBookId = null;
    public ?array $addressBooks = null;
    public int $addressBooksCount = 0;
    public int $addressBooksDone = 0;
    public ?string $currentAddressBook = null;
    public ?array $selectedAddressBooks = null;
    public int $contactsCount = 0;
    public int $contactsDone = 0;
    public int $contactsSkipped = 0;
    public ?int $updatedAt = null;
}
