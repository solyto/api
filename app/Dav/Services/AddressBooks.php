<?php

namespace App\Dav\Services;

use App\Api\Users\Models\User;
use App\Dav\Backend\AppContactsPDO;
use App\Dav\DTOs\AddressBookDTO;
use App\Dav\Helpers\DavHelper;

class AddressBooks
{
    private readonly AppContactsPDO $backend;
    private readonly Contacts       $contacts;

    public function __construct(\PDO $pdo)
    {
        $this->backend = new AppContactsPDO($pdo);
        $this->contacts = new Contacts($this->backend);
    }

    public function contacts(): Contacts
    {
        return $this->contacts;
    }

    public function list(User $user): array
    {
        return $this->backend->listAddressBooksCustom(DavHelper::getPrincipalUri($user));
    }

    public function get(User $user, string $addressBookId): ?AddressBookDTO
    {
        return $this->backend->getAddressBookByIdCustom(DavHelper::getPrincipalUri($user), $addressBookId);
    }

    public function getByName(User $user, string $name): ?AddressBookDTO
    {
        return $this->backend->getAddressBookByNameCustom(DavHelper::getPrincipalUri($user), $name);
    }

    public function create(User $user, AddressBookDTO $dto): AddressBookDTO
    {
        $this->backend->createAddressBookCustom(DavHelper::getPrincipalUri($user), $dto);

        return $this->getByName($user, $dto->displayName);
    }

    public function createDefaultAddressBook(User $user): AddressBookDTO
    {
        $default = new AddressBookDTO(
            id: 0,
            principalUri: DavHelper::getPrincipalUri($user),
            uri: 'contacts',
            displayName: 'Contacts',
            description: 'Your default contacts',
            color: '#0088CC',
        );

        return $this->create($user, $default);
    }

    public function update(User $user, AddressBookDTO $dto): ?AddressBookDTO
    {
        $this->backend->updateAddressBookCustom($dto);

        return $this->get($user, $dto->id);
    }

    public function delete(string $addressBookId): void
    {
        $this->backend->deleteAddressBookCustom($addressBookId);
    }
}
