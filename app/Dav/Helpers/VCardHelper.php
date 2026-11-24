<?php

namespace App\Dav\Helpers;

use Illuminate\Support\Facades\Log;
use Sabre\VObject\Reader;

class VCardHelper
{
    public function parseAddressBooksFromXml(string $responseBody, string $baseUrl): array
    {
        $addressBooks = [];

        try {
            $dom = new \DOMDocument;

            if (! @$dom->loadXML($responseBody)) {
                Log::error('Failed to parse address books XML', [
                    'body' => $responseBody,
                    'libxml_errors' => libxml_get_errors(),
                ]);

                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('card', 'urn:ietf:params:xml:ns:carddav');
            $xpath->registerNamespace('cs', 'http://calendarserver.org/ns/');

            $responses = $xpath->query('//d:response');

            foreach ($responses as $response) {
                $href = $xpath->query('.//d:href', $response)->item(0);
                $resourceType = $xpath->query('.//d:resourcetype/card:addressbook', $response);

                if ($resourceType->length > 0 && $href) {
                    $displayName = $xpath->query('.//d:displayname', $response)->item(0);
                    $description = $xpath->query('.//card:addressbook-description', $response)->item(0);
                    $ctag = $xpath->query('.//cs:getctag', $response)->item(0);

                    // Try to find color from whatever namespaces the server returns
                    $color = null;
                    $colorNode = $xpath->query('.//*[local-name()="addressbook-color"]', $response)->item(0);
                    if ($colorNode) {
                        $color = $colorNode->textContent;
                    }

                    // Extract address book name from href
                    $hrefPath = trim($href->textContent, '/');
                    $pathParts = explode('/', $hrefPath);
                    $addressBookName = end($pathParts);

                    $addressBooks[] = [
                        'name' => $addressBookName,
                        'url' => UrlHelper::getBaseUrl($baseUrl).$href->textContent,
                        'display_name' => $displayName ? $displayName->textContent : 'Unnamed Address Book',
                        'description' => $description ? $description->textContent : null,
                        'color' => $color,
                        'ctag' => $ctag ? $ctag->textContent : null,
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error('Error parsing address books XML: '.$e->getMessage());
        }

        return $addressBooks;
    }

    public function parseContactsFromXml(string $responseBody): array
    {
        $contacts = [];

        try {
            $dom = new \DOMDocument;
            if (! @$dom->loadXML($responseBody)) {
                Log::error('Failed to parse contacts XML');

                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('card', 'urn:ietf:params:xml:ns:carddav');

            $responses = $xpath->query('//d:response');

            foreach ($responses as $response) {
                $addressData = $xpath->query('.//card:address-data', $response)->item(0);

                if ($addressData && ! empty($addressData->textContent)) {
                    $contacts[] = $addressData->textContent;
                }
            }
        } catch (\Exception $e) {
            Log::error('Error parsing contacts XML: '.$e->getMessage());
        }

        return $contacts;
    }

    private function parseVCardData(string $vCardData, $etag, $href): ?array
    {
        try {
            $vCard = Reader::read($vCardData);

            $contact = [
                'uid' => (string) ($vCard->UID ?? ''),
                'full_name' => isset($vCard->FN) ? (string) $vCard->FN : '',
                'organization' => isset($vCard->ORG) ? (string) $vCard->ORG : null,
                'title' => isset($vCard->TITLE) ? (string) $vCard->TITLE : null,
                'note' => isset($vCard->NOTE) ? (string) $vCard->NOTE : null,
                'etag' => $etag ? trim($etag->textContent, '"') : null,
                'url' => $href ? $href->textContent : null,
            ];

            $emails = [];
            foreach ($vCard->EMAIL ?? [] as $email) {
                $emails[] = [
                    'value' => (string) $email,
                    'type' => $email['TYPE'] ? (string) $email['TYPE'] : 'INTERNET',
                ];
            }
            $contact['email'] = $emails ?: [];

            $phones = [];
            foreach ($vCard->TEL ?? [] as $tel) {
                $phones[] = [
                    'value' => (string) $tel,
                    'type' => $tel['TYPE'] ? (string) $tel['TYPE'] : 'VOICE',
                ];
            }
            $contact['phone'] = $phones ?: [];

            if (isset($vCard->CATEGORIES)) {
                $categoriesString = (string) $vCard->CATEGORIES;
                $contact['groups'] = ! empty($categoriesString) ? explode(',', $categoriesString) : [$categoriesString];
            } else {
                $contact['groups'] = [];
            }

            if (isset($vCard->ADR)) {
                $adr = (string) $vCard->ADR;
                $addressParts = explode(';', $adr);
                $contact['street'] = $addressParts[2] ?? null;
                $contact['city'] = $addressParts[3] ?? null;
                $contact['state'] = $addressParts[4] ?? null;
                $contact['postal_code'] = $addressParts[5] ?? null;
                $contact['country'] = $addressParts[6] ?? null;
            }

            if (isset($vCard->N)) {
                $nameParts = explode(';', (string) $vCard->N);
                $contact['last_name'] = $nameParts[0] ?? null;
                $contact['first_name'] = $nameParts[1] ?? null;
                $contact['middle_name'] = $nameParts[2] ?? null;
                $contact['prefix'] = $nameParts[3] ?? null;
                $contact['suffix'] = $nameParts[4] ?? null;
            }

            if (isset($vCard->PHOTO)) {
                $contact['photo'] = (string) $vCard->PHOTO;
            } else {
                $contact['photo'] = null;
            }

            return $contact;

        } catch (\Exception $e) {
            Log::error('Error parsing vCard data: '.$e->getMessage());

            return null;
        }
    }

    public function parseAddressBookHomeFromXml(string $xml, string $baseUrl): array
    {
        try {
            $dom = new \DOMDocument;

            if (! @$dom->loadXML($xml)) {
                Log::error('Failed to parse address book home XML', ['body' => $xml]);

                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('card', 'urn:ietf:params:xml:ns:carddav');

            // CardDAV equivalent of calendar-home-set
            $nodes = $xpath->query('//card:addressbook-home-set/d:href');

            $homes = [];
            foreach ($nodes as $node) {
                $homes[] = [
                    'url' => rtrim(UrlHelper::getBaseUrl($baseUrl), '/').$node->textContent,
                ];
            }

            return $homes;

        } catch (\Exception $e) {
            Log::error('Error parsing address book home XML: '.$e->getMessage());

            return [];
        }
    }

    public static function getAddressBooksXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
            <d:prop>
                <d:displayname />
                <d:resourcetype />
            </d:prop>
        </d:propfind>';
    }

    public static function getContactsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
                <card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
                    <d:prop>
                        <d:getetag />
                        <card:address-data />
                    </d:prop>
                </card:addressbook-query>';
    }

    public static function getAddressBookHomeDiscoveryXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
            <d:prop>
                <card:addressbook-home-set />
            </d:prop>
        </d:propfind>';
    }

    public static function isValid(string $vCard): bool
    {
        try {
            Reader::read($vCard);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // Forgiving parse + re-serialize: sabre's serializer produces correctly folded and escaped output and keeps
    // UID, PHOTO and the rest as they are where possible.
    public static function repair(string $vCard): ?string
    {
        try {
            $repaired = self::dropUnreadablePhoto(Reader::read($vCard, Reader::OPTION_FORGIVING)->serialize());
        } catch (\Throwable) {
            return null;
        }

        return self::isValid($repaired) ? $repaired : null;
    }

    // A repaired vCard can be structurally valid while its PHOTO payload is truncated or garbled (a forgiving parse
    // silently drops a broken fold instead of failing). No photo is better than a broken one.
    public static function dropUnreadablePhoto(string $vCard): string
    {
        if (!preg_match('/(^PHOTO[^\r\n]*(?:\r?\n[ \t][^\r\n]*)*\r?\n)/m', $vCard, $matches)) {
            return $vCard;
        }

        $photoBlock = $matches[1];
        $colonPos = strpos($photoBlock, ':');
        if ($colonPos === false) {
            return $vCard;
        }

        $value = substr($photoBlock, $colonPos + 1);

        if (stripos($value, 'data:') === 0 && str_contains($value, ',')) {
            $value = substr($value, strpos($value, ',') + 1);
        }

        $base64 = trim(preg_replace('/\r?\n[ \t]/', '', $value));
        $decoded = base64_decode($base64, true);

        if ($decoded !== false && @getimagesizefromstring($decoded) !== false) {
            return $vCard;
        }

        return str_replace($photoBlock, '', $vCard);
    }
}
