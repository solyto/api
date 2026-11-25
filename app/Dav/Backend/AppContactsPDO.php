<?php

namespace App\Dav\Backend;

use App\Dav\DTOs\AddressBookDTO;
use App\Dav\DTOs\ContactDTO;
use App\Dav\Services\VCardPhotoProcessor;
use Illuminate\Support\Str;
use Sabre\CardDAV\Backend\PDO as SabrePDO;

class AppContactsPDO extends SabrePDO
{
    // addressbooks.uri is VARCHAR(200); the rest is for uniqueUri()'s "-n" suffix.
    private const int MAX_URI_BASE_LENGTH = 190;

    public function listAddressBooksCustom(string $principalUri): array
    {
        $stmt = $this->pdo->prepare('SELECT id, uri, displayname, color, principaluri, description, synctoken FROM '.$this->addressBooksTableName.' WHERE principaluri = ?');
        $stmt->execute([$principalUri]);

        $addressBooks = [];

        foreach ($stmt->fetchAll() as $row) {
            $addressBooks[] = AddressBookDTO::fromSabre($row);
        }

        return $addressBooks;
    }

    public function getAddressBookByIdCustom(string $principalUri, int $id): ?AddressBookDTO
    {
        $stmt = $this->pdo->prepare('SELECT id, uri, displayname, color, principaluri, description, synctoken FROM '.$this->addressBooksTableName.' WHERE principaluri = ? AND id = ? LIMIT 1');
        $stmt->execute([$principalUri, $id]);
        $addressBook = $stmt->fetch();

        return $addressBook ? AddressBookDTO::fromSabre($addressBook) : null;
    }

    public function getAddressBookByNameCustom(string $principalUri, string $displayName): ?AddressBookDTO
    {
        $stmt = $this->pdo->prepare('SELECT id, uri, displayname, color, principaluri, description, synctoken FROM '.$this->addressBooksTableName.' WHERE principaluri = ? AND displayname = ? LIMIT 1');
        $stmt->execute([$principalUri, $displayName]);
        $addressBook = $stmt->fetch();

        return $addressBook ? AddressBookDTO::fromSabre($addressBook) : null;
    }

    public function createAddressBookCustom(string $principalUri, AddressBookDTO $dto): string
    {
        $values = [
            'displayname' => $dto->displayName,
            'description' => $dto->description,
            'color' => $dto->color,
            'principaluri' => $principalUri,
            'uri' => $this->uniqueUri($principalUri, $dto->displayName),
        ];

        $query = 'INSERT INTO '.$this->addressBooksTableName.' (uri, displayname, description, color, principaluri, synctoken) VALUES (:uri, :displayname, :description, :color, :principaluri, 1)';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($values);

        return $this->pdo->lastInsertId(
            $this->addressBooksTableName.'_id_seq'
        );
    }

    // (principaluri, uri) is unique, but different display names can slug alike ("Work" and "work!") or to nothing
    // (emoji-only names). Transliteration can make the slug longer than the name (one Cyrillic letter can become
    // three), so it is cut to leave room for a "-n" suffix in uri's VARCHAR(200).
    private function uniqueUri(string $principalUri, string $displayName): string
    {
        $base = rtrim(substr(Str::slug($displayName), 0, self::MAX_URI_BASE_LENGTH), '-');
        if ($base === '') {
            return Str::uuid()->toString();
        }

        $candidate = $base;
        $i = 1;
        while ($this->uriTaken($principalUri, $candidate)) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    private function uriTaken(string $principalUri, string $uri): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM '.$this->addressBooksTableName.' WHERE principaluri = ? AND uri = ?'
        );
        $stmt->execute([$principalUri, $uri]);

        return $stmt->fetchColumn() !== false;
    }

    public function updateAddressBookCustom(AddressBookDTO $dto): bool
    {
        $updates = [];
        $params = [];

        if (!empty($dto->displayName)) {
            $updates[] = 'displayname = :displayname';
            $params[':displayname'] = $dto->displayName;
        }

        if (!empty($dto->description)) {
            $updates[] = 'description = :description';
            $params[':description'] = $dto->description;
        }

        if (!empty($dto->color)) {
            $updates[] = 'color = :color';
            $params[':color'] = $dto->color;
        }

        if (empty($updates)) {
            return true;
        }

        $setClause = implode(', ', $updates);
        $query = 'UPDATE ' . $this->addressBooksTableName . ' SET ' . $setClause . ' WHERE id = :addressbookid';
        $params[':addressbookid'] = $dto->id;

        try {
            $stmt = $this->pdo->prepare($query);
            $result = $stmt->execute($params);

            if (!$result) {
                throw new \PDOException('Update failed: ' . implode(', ', $stmt->errorInfo()));
            }

            $this->addChange($dto->id, '', 2);
            return true;

        } catch (\PDOException $e) {
            \Log::error('Addressbook update failed', [
                'dto_id' => $dto->id,
                'query' => $query,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function deleteAddressBookCustom(int $addressBookId): void
    {
        parent::deleteAddressBook($addressBookId);
    }

    public function getContactsCustom(AddressBookDTO $addressBook): array
    {
        $stmt = $this->pdo->prepare('SELECT id, uri, lastmodified, carddata, etag, size FROM '.$this->cardsTableName.' WHERE addressbookid = ?');
        $stmt->execute([$addressBook->id]);

        $contacts = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $row['etag'] = '"'.$row['etag'].'"';
            $row['lastmodified'] = (int) $row['lastmodified'];
            $contacts[] = ContactDTO::fromSabre($row, $addressBook, false);
        }

        return $contacts;
    }

    // For the export: one card at a time, photos included, with the stored card kept as it is.
    public function getStoredContactsCustom(AddressBookDTO $addressBook): \Generator
    {
        $stmt = $this->pdo->prepare('SELECT id, uri, carddata FROM '.$this->cardsTableName.' WHERE addressbookid = ?');
        $stmt->execute([$addressBook->id]);

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (is_resource($row['carddata'])) {
                $row['carddata'] = stream_get_contents($row['carddata']);
            }

            $contact = ContactDTO::fromSabre($row, $addressBook);
            $contact->storedData = $row['carddata'] ?: null;

            yield $contact;
        }
    }

    public function getContactCustom(AddressBookDTO $addressBook, string $cardUri): ?ContactDTO
    {
        $card = parent::getCard($addressBook->id, $cardUri);

        return $card ? ContactDTO::fromSabre($card, $addressBook) : null;
    }

    public function createContactCustom(AddressBookDTO $addressBook, $cardData): ?string
    {
        $cardUri = bin2hex(Str::random(16)) . '.vcf';

        parent::createCard($addressBook->id, $cardUri, $cardData);

        return $cardUri;
    }

    public function updateContactCustom(AddressBookDTO $addressBook, ContactDTO $dto): ?string
    {
        return parent::updateCard($addressBook->id, $dto->uri, $dto->toVCard());
    }

    public function deleteContactCustom(AddressBookDTO $addressBook, string $cardUri): bool
    {
        return parent::deleteCard($addressBook->id, $cardUri);
    }

    public function createCard($addressBookId, $cardUri, $cardData): string
    {
        return parent::createCard($addressBookId, $cardUri, app(VCardPhotoProcessor::class)->process($cardData));
    }

    public function updateCard($addressBookId, $cardUri, $cardData): ?string
    {
        return parent::updateCard($addressBookId, $cardUri, app(VCardPhotoProcessor::class)->process($cardData));
    }
}
