<?php

namespace App\Dav\DTOs;

class AddressBookImportDTO
{
    public string $jobId;
    public string $userEmail;
    public string $stage = 'started' | 'select' | 'address-books' | 'contacts' | 'finished';
    public string $url;
    public string $username;
    public string $secret;
    public ?array $addressBooks = null;
    public int $addressBooksCount = 0;
    public int $addressBooksDone = 0;
    public ?string $currentAddressBook = null;
    public ?array $selectedAddressBooks = null;
    public int $contactsCount = 0;
    public int $contactsDone = 0;
}
