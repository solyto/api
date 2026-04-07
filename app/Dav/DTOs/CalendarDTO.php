<?php

namespace App\Dav\DTOs;

use App\Dav\Models\CalendarInstance;

class CalendarDTO
{
    public function __construct(
        public int $calendarId,
        public int $instanceId,
        public ?string $name,
        public ?string $displayName = null,
        public ?string $color = null,
        public ?string $description = null,
        public ?string $timezone = null,
        public ?string $syncToken = null,
        public ?string $uri = null,
        public ?string $shareOwner = null,
        public bool $isShared = false,
        public bool $isDefault = false,
        public ?string $inviteStatus = null,
        public ?string $shareToken = null,
    ) {}

    public static function fromSabre(array $data): self
    {
        $inviteStatus = null;
        if (isset($data['share-invitestatus'])) {
            $inviteStatus = match ((int) $data['share-invitestatus']) {
                1 => 'pending',
                2 => 'accepted',
                3 => 'declined',
                default => null,
            };
        }

        return new self(
            calendarId: $data['id'][0] ?? null,
            instanceId: $data['id'][1] ?? null,
            name: $data['uri'] ?? null,
            displayName: $data['{DAV:}displayname'] ?? null,
            color: $data['{http://apple.com/ns/ical/}calendar-color'] ?? null,
            description: $data['{urn:ietf:params:xml:ns:caldav}calendar-description'] ?? null,
            timezone: $data['{urn:ietf:params:xml:ns:caldav}calendar-timezone'] ?? null,
            syncToken: $data['{http://sabredav.org/ns}sync-token'] ?? null,
            uri: $data['uri'] ?? null,
            shareOwner: $data['share-resource-uri'] ?? null,
            isShared: !empty($data['share-access']) && $data['share-access'] > 1,
            isDefault: $data['uri'] === 'default',
            inviteStatus: $inviteStatus,
            shareToken: $data['share-href'] ?? null,
        );
    }


    public static function fromRequest(array $data): self
    {
        return new self(
            calendarId: 0,
            instanceId: 0,
            name: null,
            displayName: $data['name'],
            color: $data['color'] ?? '#0088CC',
            description: $data['description'] ?? null,
            timezone: $data['timezone'] ?? 'UTC',
            syncToken: null,
            uri: null,
            shareOwner: null,
            isShared: false,
            isDefault: $data['is_default'] ?? false
        );
    }

    public static function fromImport(array $data): self
    {
        return new self(
            calendarId: 0,
            instanceId: 0,
            name: null,
            displayname: $data['displayname'] ?? $data['name'],
            color: $data['color'] ?? '#0088CC',
            description: $data['description'] ?? null,
            timezone: $data['timezone'] ?? 'UTC',
            syncToken: $data['synctoken'] ?? null,
            uri: null,
            shareOwner: null,
            isShared: false,
            isDefault: $data['is_default'] ?? false
        );
    }
}
