<?php

use App\Dav\Helpers\VCardHelper;

describe('VCardHelper::parseAddressBooksFromXml', function () {
    it('parses address books from a multistatus response', function () {
        $xml = <<<'XML'
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav" xmlns:cs="http://calendarserver.org/ns/">
  <d:response>
    <d:href>/dav/addressbooks/john/contacts/</d:href>
    <d:propstat>
      <d:prop>
        <d:displayname>Contacts</d:displayname>
        <d:resourcetype><card:addressbook/></d:resourcetype>
        <card:addressbook-description>My contacts</card:addressbook-description>
        <cs:getctag>12345</cs:getctag>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>
XML;

        $books = (new VCardHelper)->parseAddressBooksFromXml($xml, 'https://nc.example.com');

        expect($books)->toHaveCount(1);
        expect($books[0]['name'])->toBe('contacts');
        expect($books[0]['url'])->toBe('https://nc.example.com/dav/addressbooks/john/contacts/');
        expect($books[0]['display_name'])->toBe('Contacts');
        expect($books[0]['description'])->toBe('My contacts');
        expect($books[0]['ctag'])->toBe('12345');
    });

    it('returns an empty array for invalid xml', function () {
        expect((new VCardHelper)->parseAddressBooksFromXml('garbage', 'https://x.com'))->toBe([]);
    });
});

describe('VCardHelper::parseContactsFromXml', function () {
    it('parses vcard data from a query response', function () {
        $vcard = <<<'VCARD'
BEGIN:VCARD
VERSION:3.0
FN:John Doe
EMAIL:john@example.com
END:VCARD
VCARD;

        $xml = '<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/c1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <card:address-data>' . htmlspecialchars($vcard) . '</card:address-data>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>';

        $contacts = (new VCardHelper)->parseContactsFromXml($xml);

        expect($contacts)->toHaveCount(1);
        expect($contacts[0])->toContain('FN:John Doe');
    });

    it('returns an empty array for invalid xml', function () {
        expect((new VCardHelper)->parseContactsFromXml('nope'))->toBe([]);
    });
});

describe('VCardHelper::parseAddressBookHomeFromXml', function () {
    it('parses the address book home set', function () {
        $xml = <<<'XML'
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/</d:href>
    <d:propstat>
      <d:prop>
        <card:addressbook-home-set>
          <d:href>/remote.php/dav/addressbooks/john/</d:href>
        </card:addressbook-home-set>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>
XML;

        $homes = (new VCardHelper)->parseAddressBookHomeFromXml($xml, 'https://nc.example.com');

        expect($homes)->toHaveCount(1);
        expect($homes[0]['url'])->toBe('https://nc.example.com/remote.php/dav/addressbooks/john/');
    });
});

describe('VCardHelper xml builders', function () {
    it('builds the address books propfind xml', function () {
        $xml = VCardHelper::getAddressBooksXml();

        expect($xml)->toBeString();
        expect($xml)->toContain('propfind');
        expect($xml)->toContain('carddav');
    });

    it('builds the contacts query xml', function () {
        $xml = VCardHelper::getContactsXml();

        expect($xml)->toBeString();
        expect($xml)->toContain('addressbook-query');
        expect($xml)->toContain('address-data');
    });

    it('builds the address book home discovery xml', function () {
        $xml = VCardHelper::getAddressBookHomeDiscoveryXml();

        expect($xml)->toBeString();
        expect($xml)->toContain('addressbook-home-set');
    });
});
