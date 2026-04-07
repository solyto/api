<?php

namespace App\Dav\DTOs;

use Illuminate\Support\Str;

class AddressBookDTO
{
    public function __construct(
        public int $id,
        public string $principalUri,
        public ?string $uri = null,
        public ?string $displayName = null,
        public ?string $description = null,
        public ?string $syncToken = null,
        public ?string $color = null,
    ) {}

    public static function fromSabre(array $row): self
    {
        return new self(
            id: (int)($row['id'] ?? 0),
            principalUri: $row['principaluri'] ?? '',
            uri: $row['uri'] ?? null,
            displayName: $row['displayname'] ?? null,
            description: $row['description'] ?? null,
            syncToken: isset($row['synctoken']) ? (string)$row['synctoken'] : null,
            color: $row['color'] ?? null
        );
    }

    public static function fromRequest(array $data): self
    {
        return new self(
            id: 0,
            principalUri: $data['principal_uri'] ?? '',
            uri: null,
            displayName: $data['display_name'] ?? $data['name'] ?? null,
            description: $data['description'] ?? null,
            syncToken: null,
            color: $data['color'] ?? '#0088CC'
        );
    }

    public static function fromImport(array $data): self
    {
        return new self(
            id: 0,
            principalUri: $data['principal_uri'] ?? '',
            uri: null,
            displayName: $data['displayname'] ?? $data['name'],
            description: $data['description'] ?? null,
            syncToken: $data['synctoken'] ?? null,
            color: $data['color'] ?? '#0088CC'
        );
    }
}
