<?php

namespace App\Dav\DTOs;

use App\Dav\Models\Card;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ContactDTO
{
    public function __construct(
        public ?string $uid,
        public string $firstName,
        public ?string $middleName,
        public string  $lastName,
        public string  $fullName,
        public ?string $email,
        public ?string $phone,
        public ?string $organization,
        public ?string $street,
        public ?string $city,
        public ?string $state,
        public ?string $postalCode,
        public ?string $country,
        public ?string $photo,
        public ?string $groups,
        public ?string $prefix,
        public ?string $suffix,
        public ?string $note,
        public ?string $uri,
        public ?string $etag,
        public ?int    $addressBookId,
        public ?string $addressBookColor = null,
    ) {}

    public static function fromSabre(array $row, AddressBookDTO $addressBook, bool $includePhoto = true): self
    {
        $vCardData = $row['carddata'] ?? '';
        if (is_resource($vCardData)) {
            $vCardData = stream_get_contents($vCardData);
        }

        $parsed = self::parseVCard($vCardData, $includePhoto);

        return new self(
            uid: $row['id'] ?? null,
            firstName: $parsed['first_name'] ?? '',
            middleName: $parsed['middle_name'] ?? null,
            lastName: $parsed['last_name'] ?? '',
            fullName: $parsed['full_name'] ?? '',
            email: $parsed['email'] ?? null,
            phone: $parsed['phone'] ?? null,
            organization: $parsed['organization'] ?? null,
            street: $parsed['street'] ?? null,
            city: $parsed['city'] ?? null,
            state: $parsed['state'] ?? null,
            postalCode: $parsed['postal_code'] ?? null,
            country: $parsed['country'] ?? null,
            photo: $includePhoto ? ($parsed['photo'] ?? null) : null,
            groups: $parsed['groups'] ?? null,
            prefix: $parsed['prefix'] ?? null,
            suffix: $parsed['suffix'] ?? null,
            note: $parsed['note'] ?? null,
            uri: $row['uri'] ?? null,
            etag: $row['etag'] ?? null,
            addressBookId: $addressBook->id,
            addressBookColor: $addressBook->color
        );
    }

    public static function fromRequest(array $data, AddressBookDTO $addressBook): self
    {
        return new self(
            uid             : $data['uid'] ?? Str::uuid()->toString(),
            firstName       : $data['first_name'],
            middleName      : $data['middle_name'] ?? null,
            lastName        : $data['last_name'],
            fullName        : $data['full_name'] ?? $data['first_name'].' '.$data['last_name'],
            email           : $data['email'] ?? null,
            phone           : $data['phone'] ?? null,
            organization    : $data['organization'] ?? null,
            street          : $data['street'] ?? null,
            city            : $data['city'] ?? null,
            state           : $data['state'] ?? null,
            postalCode      : $data['postal_code'] ?? null,
            country         : $data['country'] ?? null,
            photo           : $data['photo_path'] ?? null,
            groups          : $data['groups'] ?? null,
            prefix          : $data['prefix'] ?? null,
            suffix          : $data['suffix'] ?? null,
            note            : $data['note'] ?? null,
            uri             : null,
            etag            : null,
            addressBookId   : $addressBook->id,
            addressBookColor: $addressBook->color
        );
    }

    public static function fromImport(array $parsed, AddressBookDTO $addressBook): self
    {
        return new self(
            uid             : Str::uuid()->toString(),
            firstName       : $parsed['first_name'] ?? '',
            middleName      : $parsed['middle_name'] ?? null,
            lastName        : $parsed['last_name'] ?? '',
            fullName        : $parsed['full_name'] ?? '',
            email           : is_array($parsed['email']) ? json_encode($parsed['email']) : $parsed['email'] ?? null,
            phone           : is_array($parsed['phone']) ? json_encode($parsed['phone']) : $parsed['phone'] ?? null,
            organization    : $parsed['organization'] ?? null,
            street          : $parsed['street'] ?? null,
            city            : $parsed['city'] ?? null,
            state           : $parsed['state'] ?? null,
            postalCode      : $parsed['postal_code'] ?? null,
            country         : $parsed['country'] ?? null,
            photo           : $parsed['photo'] ?? null,
            groups          : is_array($parsed['groups']) ? json_encode($parsed['groups']) : $parsed['groups'] ?? null,
            prefix          : $parsed['prefix'] ?? null,
            suffix          : $parsed['suffix'] ?? null,
            note            : $parsed['note'] ?? null,
            uri             : $parsed['uri'] ?? null,
            etag            : $parsed['etag'] ?? null,
            addressBookId   : $addressBook->id,
            addressBookColor: $addressBook->color
        );
    }

    public function updateFromRequest(array $data): void
    {
        $this->firstName = $data['first_name'] ?? $this->firstName;
        $this->middleName = $data['middle_name'] ?? $this->middleName;
        $this->lastName = $data['last_name'] ?? $this->lastName;
        $this->fullName = $data['full_name'] ?? $this->firstName . ' ' . $this->lastName;
        $this->email = $data['email'] ?? $this->email;
        $this->phone = $data['phone'] ?? $this->phone;
        $this->organization = $data['organization'] ?? $this->organization;
        $this->street = $data['street'] ?? $this->street;
        $this->city = $data['city'] ?? $this->city;
        $this->state = $data['state'] ?? $this->state;
        $this->postalCode = $data['postal_code'] ?? $this->postalCode;
        $this->country = $data['country'] ?? $this->country;
        $this->photo = $data['photo_path'] ?? $this->photo;
        $this->groups = $data['groups'] ?? $this->groups;
        $this->prefix = $data['prefix'] ?? $this->prefix;
        $this->suffix = $data['suffix'] ?? $this->suffix;
        $this->note = $data['note'] ?? $this->note;
        $this->uri = $data['uri'] ?? $this->uri;
        $this->etag = $data['etag'] ?? $this->etag;
    }

    public function updatePhoto(UploadedFile $file): void
    {
        $extension = strtoupper($file->extension() ?: 'JPEG');
        $base64Photo = base64_encode(file_get_contents($file->getRealPath()));

        $lines = str_split($base64Photo, 76);

        $photoLine = "PHOTO;ENCODING=BASE64;TYPE={$extension}:" . array_shift($lines);
        foreach ($lines as $line) {
            $photoLine .= "\r\n " . $line;
        }

        $this->photo = $photoLine;
    }

    public function toVCard(): string
    {
        $lines = ["BEGIN:VCARD", "VERSION:3.0"];

        // Name
        $lines[] = 'FN:' . $this->fullName;
        $lines[] = 'N:' . $this->lastName . ';' . $this->firstName . ';' . ($this->middleName ?? '') . ';' . ($this->prefix ?? '') . ';' . ($this->suffix ?? '');

        // Email
        if ($this->email) {
            if (is_string($this->email) && str_starts_with($this->email, '[')) {
                $emailData = json_decode($this->email, true);
                if (is_array($emailData)) {
                    foreach ($emailData as $entry) {
                        $type = strtoupper($entry['type'] ?? 'INTERNET');
                        $value = preg_replace('/[^\P{C}\n]+/u', '', $entry['value']);
                        $lines[] = "EMAIL;TYPE={$type}:{$value}";
                    }
                }
            } else {
                $lines[] = 'EMAIL:' . preg_replace('/[^\P{C}\n]+/u', '', $this->email);
            }
        }

        // Phone
        if ($this->phone) {
            if (is_string($this->phone) && str_starts_with($this->phone, '[')) {
                $phoneData = json_decode($this->phone, true);
                if (is_array($phoneData)) {
                    foreach ($phoneData as $entry) {
                        $type = strtoupper($entry['type'] ?? 'VOICE');
                        $value = preg_replace('/[^\P{C}\n]+/u', '', $entry['value']);
                        $lines[] = "TEL;TYPE={$type}:{$value}";
                    }
                }
            } else {
                $lines[] = 'TEL:' . preg_replace('/[^\P{C}\n]+/u', '', $this->phone);
            }
        }

        // Organization
        if ($this->organization) $lines[] = 'ORG:' . preg_replace('/[^\P{C}\n]+/u', '', $this->organization);

        // Groups
        if ($this->groups) {
            $decoded = json_decode($this->groups, true);
            $groupNames = is_array($decoded) ? $decoded : [$this->groups];
            $lines[] = 'CATEGORIES:' . implode(',', array_map(fn($g) => preg_replace('/[^\P{C}\n]+/u', '', $g), $groupNames));
        }

        // Address
        if ($this->street || $this->city || $this->state || $this->postalCode || $this->country) {
            $lines[] = 'ADR:;;' .
                ($this->street ?? '') . ';' .
                ($this->city ?? '') . ';' .
                ($this->state ?? '') . ';' .
                ($this->postalCode ?? '') . ';' .
                ($this->country ?? '');
        }

        // Photo
        if ($this->photo) {
            $lines[] = $this->photo;
        }

        // Note
        if ($this->note) $lines[] = 'NOTE:' . preg_replace('/[^\P{C}\n]+/u', '', $this->note);

        // URI
        if ($this->uri) $lines[] = 'uri:' . preg_replace('/[^\P{C}\n]+/u', '', $this->uri);

        // UID
        if ($this->uid) $lines[] = 'UID:' . preg_replace('/[^\P{C}\n]+/u', '', $this->uid);

        $lines[] = "END:VCARD";

        return implode("\r\n", $lines);
    }



    public static function parseVCard(string $vcard, bool $includePhoto = true): array
    {
        $lines = explode("\n", str_replace(["\r\n","\r"], "\n", $vcard));
        $result = [
            'full_name' => null,
            'first_name' => null,
            'last_name' => null,
            'middle_name' => null,
            'prefix' => null,
            'suffix' => null,
            'phone' => [],
            'email' => [],
            'organization' => null,
            'groups' => null,
            'note' => null,
            'street' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country' => null,
            'uri' => null,
            'uid' => null,
            'photo' => null,
        ];

        $photoLines = [];
        $isPhoto = false;

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (str_starts_with($line, 'PHOTO')) {
                $isPhoto = true;
                if ($includePhoto) {
                    $photoLines[] = $line;
                }
                continue;
            }

            // Continuation line for folded photo
            if ($isPhoto && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
                if ($includePhoto) {
                    $photoLines[] = $line;
                }
                continue;
            }

            // End of PHOTO field
            if ($isPhoto) {
                if ($includePhoto) {
                    $result['photo'] = implode("\r\n", $photoLines);
                }
                $photoLines = [];
                $isPhoto = false;
            }

            if (str_starts_with($line, 'FN:')) $result['full_name'] = substr($line, 3);
            elseif (str_starts_with($line, 'N:')) {
                $parts = explode(';', substr($line, 2));
                $result['last_name'] = $parts[0] ?? '';
                $result['first_name'] = $parts[1] ?? '';
                $result['middle_name'] = $parts[2] ?? '';
                $result['prefix'] = $parts[3] ?? '';
                $result['suffix'] = $parts[4] ?? '';
            }
            elseif (str_starts_with($line, 'TEL')) {
                if (preg_match('/TEL(;TYPE=([^:]+))?:(.*)$/i', $line, $matches)) {
                    $type = $matches[2] ?? 'VOICE';
                    $value = $matches[3];
                    $result['phone'][] = ['type' => strtolower($type), 'value' => $value];
                }
            }
            elseif (str_starts_with($line, 'EMAIL')) {
                if (preg_match('/EMAIL(;TYPE=([^:]+))?:(.*)$/i', $line, $matches)) {
                    $type = $matches[2] ?? 'INTERNET';
                    $value = $matches[3];
                    $result['email'][] = ['type' => strtolower($type), 'value' => $value];
                }
            }
            elseif (str_starts_with($line, 'ORG:')) $result['organization'] = substr($line, 4);
            elseif (str_starts_with($line, 'CATEGORIES:')) {
                $cats = array_map('trim', explode(',', substr($line, 11)));
                $result['groups'] = json_encode(array_values(array_filter($cats)));
            }
            elseif (str_starts_with($line, 'NOTE:')) $result['note'] = substr($line, 5);
            elseif (str_starts_with($line, 'ADR')) {
                $adrValue = preg_replace('/^ADR[^:]*:/', '', $line);
                $parts = explode(';', $adrValue);
                // vCard ADR: POBox;ExtAddr;Street;City;State;PostalCode;Country (7 parts)
                // Legacy format written by old code: ;Street;City;State;PostalCode;Country (6 parts)
                $offset = count($parts) >= 7 ? 2 : 1;
                $result['street'] = $parts[$offset] ?? null;
                $result['city'] = $parts[$offset + 1] ?? null;
                $result['state'] = $parts[$offset + 2] ?? null;
                $result['postal_code'] = $parts[$offset + 3] ?? null;
                $result['country'] = $parts[$offset + 4] ?? null;
            }
            elseif (str_starts_with($line, 'uri:')) $result['uri'] = substr($line, 4);
            elseif (str_starts_with($line, 'UID:')) $result['uid'] = substr($line, 4);
        }

        // Catch photo if it's the last field
        if ($isPhoto) {
            $result['photo'] = implode("\r\n", $photoLines);
        }

        $result['phone'] = !empty($result['phone']) ? json_encode($result['phone']) : null;
        $result['email'] = !empty($result['email']) ? json_encode($result['email']) : null;

        return $result;
    }

}
