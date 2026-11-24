<?php

use App\Api\Contacts\Services\ContactService;
use App\Api\Export\Services\ContactExportService;
use Illuminate\Support\Facades\DB;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Splitter\VCard as VCardSplitter;

function exportContactsFile(\App\Api\Users\Models\User $user): string
{
    $path = tempnam(sys_get_temp_dir(), 'contact-export');
    app(ContactExportService::class)->export($user, $path);
    $exported = file_get_contents($path);
    unlink($path);

    return $exported;
}

/** @return array<string, VCard> */
function exportContacts(\App\Api\Users\Models\User $user): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, exportContactsFile($user));
    rewind($stream);

    $cards = [];
    $splitter = new VCardSplitter($stream);
    while ($card = $splitter->getNext()) {
        $cards[(string) $card->FN] = $card;
    }

    return $cards;
}

beforeEach(function () {
    $this->user = davUser();
    $this->book = 'addressbooks/'.$this->user->email.'/contacts/';
});

describe('Contact export', function () {
    it('exports a card stored over DAV with every property it has', function () {
        davRequest($this->user, 'PUT', $this->book.'full.vcf', davVcard('full', 'Jane Doe', [
            'EMAIL;TYPE=HOME:jane@home.example', 'EMAIL;TYPE=WORK:jane@work.example',
            'TEL;TYPE=CELL:+49 170 1111111', 'TEL;TYPE=WORK:+49 30 2222222',
            'ADR;TYPE=HOME:;;Home Street 1;Berlin;;10115;Germany', 'ADR;TYPE=WORK:;;Work Street 2;Munich;;80331;Germany',
            'BDAY:1990-04-01', 'URL:https://jane.example', 'X-CUSTOM-FIELD:kept',
        ]), ['Content-Type' => 'text/vcard']);

        $card = exportContacts($this->user)['Jane Doe'];
        $values = fn (string $name) => collect($card->select($name))
            ->map(fn ($property) => strtoupper((string) $property['TYPE']).'|'.$property->getValue())->sort()->values()->all();

        expect($values('EMAIL'))->toBe(['HOME|jane@home.example', 'WORK|jane@work.example'])
            ->and($values('TEL'))->toBe(['CELL|+49 170 1111111', 'WORK|+49 30 2222222'])
            ->and($values('ADR'))->toBe(['HOME|;;Home Street 1;Berlin;;10115;Germany', 'WORK|;;Work Street 2;Munich;;80331;Germany'])
            ->and((string) $card->BDAY)->toBe('1990-04-01')
            ->and((string) $card->URL)->toBe('https://jane.example')
            ->and((string) $card->{'X-CUSTOM-FIELD'})->toBe('kept')
            ->and((string) $card->UID)->toBe('full');
    });

    it('exports a contact created in the app', function () {
        $service = app(ContactService::class);
        $service->createContact($this->user, $service->listAddressBooks($this->user)[0], [
            'first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@example.org', 'phone' => '+49 30 1234567',
            'organization' => 'Acme', 'street' => 'Main Street 1', 'city' => 'Berlin', 'note' => 'Met at work',
        ]);

        $card = exportContacts($this->user)['John Smith'];

        expect((string) $card->N)->toBe('Smith;John;;;')
            ->and((string) $card->EMAIL)->toBe('john@example.org')
            ->and((string) $card->TEL)->toBe('+49 30 1234567')
            ->and((string) $card->ORG)->toBe('Acme')
            ->and((string) $card->ADR)->toContain('Main Street 1;Berlin')
            ->and((string) $card->NOTE)->toBe('Met at work');
    });

    it('exports a card with its photo', function () {
        $photo = base64_encode('fake-image-bytes');
        davRequest($this->user, 'PUT', $this->book.'photo.vcf', davVcard('photo', 'Jane Doe', ['PHOTO;ENCODING=b;TYPE=JPEG:'.$photo]), ['Content-Type' => 'text/vcard']);

        $card = exportContacts($this->user)['Jane Doe'];

        expect(isset($card->PHOTO))->toBeTrue()
            ->and(base64_encode($card->PHOTO->getValue()))->toBe($photo);
    });

    it('exports the rebuilt card when the stored one is unreadable', function () {
        $bookId = app(ContactService::class)->listAddressBooks($this->user)[0]->id;
        DB::connection('pgsql')->table('cards')->insert([
            'addressbookid' => $bookId, 'uri' => 'broken.vcf', 'lastmodified' => time(), 'etag' => md5('broken'), 'size' => 0,
            'carddata' => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Broken Bob\r\nN:Bob;Broken;;;\r\nEMAIL:bob@example.org\r\nnot a property line\r\nEND:VCARD\r\n",
        ]);
        davRequest($this->user, 'PUT', $this->book.'ok.vcf', davVcard('ok', 'Jane Doe'), ['Content-Type' => 'text/vcard']);

        $cards = exportContacts($this->user);

        expect(array_keys($cards))->toEqualCanonicalizing(['Broken Bob', 'Jane Doe'])
            ->and((string) $cards['Broken Bob']->EMAIL)->toBe('bob@example.org');
    });

    it('exports the contacts of every address book as one valid file', function () {
        davRequest($this->user, 'MKCOL', 'addressbooks/'.$this->user->email.'/work/', '<?xml version="1.0"?>'
            .'<d:mkcol xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:set><d:prop>'
            .'<d:resourcetype><d:collection/><card:addressbook/></d:resourcetype></d:prop></d:set></d:mkcol>');
        davRequest($this->user, 'PUT', $this->book.'a.vcf', davVcard('a', 'Jane Doe'), ['Content-Type' => 'text/vcard']);
        davRequest($this->user, 'PUT', $this->book.'b.vcf', davVcard('b', 'John Doe'), ['Content-Type' => 'text/vcard']);
        davRequest($this->user, 'PUT', 'addressbooks/'.$this->user->email.'/work/c.vcf', davVcard('c', 'Max Doe'), ['Content-Type' => 'text/vcard']);

        $file = exportContactsFile($this->user);

        expect(array_keys(exportContacts($this->user)))->toEqualCanonicalizing(['Jane Doe', 'John Doe', 'Max Doe'])
            ->and(substr_count($file, "END:VCARD\r\n"))->toBe(3)
            ->and($file)->toEndWith("END:VCARD\r\n");
    });

    it('writes an empty file when there are no contacts', function () {
        expect(exportContactsFile($this->user))->toBe('');
    });
});
