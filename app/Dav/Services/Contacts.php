<?php

namespace App\Dav\Services;

use App\Dav\Backend\AppContactsPDO;
use App\Dav\DTOs\AddressBookDTO;
use App\Dav\DTOs\ContactDTO;
use Sabre\CardDAV\Backend\PDO as SabreBackend;

class Contacts
{
    private readonly AppContactsPDO $backend;

    public function __construct(AppContactsPDO $backend)
    {
        $this->backend = $backend;
    }

    public function list(AddressBookDTO $addressBook): array
    {
        return $this->backend->getContactsCustom($addressBook);
    }

    public function get(AddressBookDTO $addressBook, string $uri): ?ContactDTO
    {
        return $this->backend->getContactCustom($addressBook, $uri);
    }

    public function create(AddressBookDTO $addressBook, ContactDTO $dto): ContactDTO
    {
        $uri = $this->backend->createContactCustom($addressBook, $dto->toVCard());

        return $this->backend->getContactCustom($addressBook, $uri);
    }

    public function import(AddressBookDTO $addressBook, string $vCard): bool
    {
        return !empty($this->backend->createContactCustom($addressBook, $vCard));
    }

    public function update(AddressBookDTO $addressBook, ContactDTO $dto): ?ContactDTO
    {
        $success = $this->backend->updateContactCustom($addressBook, $dto);

        if (!$success) {
            return null;
        }

        return $this->backend->getContactCustom($addressBook, $dto->uri);
    }

    public function delete(AddressBookDTO $addressBook, ContactDTO $dto): bool
    {
        return $this->backend->deleteContactCustom($addressBook, $dto->uri);
    }
}
