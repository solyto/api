<?php

use App\Api\Contacts\Jobs\ImportContactsFile;
use App\Api\Export\Services\ContactExportService;
use App\Api\Users\Models\User;
use App\Dav\DTOs\AddressBookImportDTO;
use App\Dav\Exceptions\ImportFileException;
use App\Dav\Services\ContactImport;
use App\Dav\Services\DavService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Sabre\VObject\Reader;

beforeEach(function () {
    Storage::fake('local');
});

function vcfFile(array $cards, string $filename = 'friends.vcf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($filename, implode('', $cards));
}

function vcfFixture(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path("tests/Fixtures/dav/{$name}")));
}

function importVcf($test, User $user, UploadedFile|string $file, array $fields = [])
{
    return $test->actingAs($user, 'sanctum')->post('/api/v1/address-books/import/file', ['file' => $file, ...$fields], ['Accept' => 'application/json']);
}

function addressBookNamed($test, User $user, string $name): ?array
{
    return collect($test->actingAs($user, 'sanctum')->getJson('/api/v1/address-books')->json('data'))->firstWhere('name', $name);
}

/** @return array<int, string> */
function storedCards(int $addressBookId): array
{
    return DB::connection('pgsql')->table('cards')->where('addressbookid', $addressBookId)->orderBy('id')->pluck('carddata')
        ->map(fn ($data) => is_resource($data) ? stream_get_contents($data) : $data)
        ->all();
}

function contactsImportState($test, User $user): array
{
    return $test->actingAs($user, 'sanctum')->getJson('/api/v1/address-books/import/state')->assertStatus(200)->json('data');
}

function cardsByName(int $addressBookId): array
{
    return collect(storedCards($addressBookId))->keyBy(fn (string $card) => (string) Reader::read($card)->FN)->all();
}

describe('Contacts file import', function () {
    it('imports the contacts into a new address book named after the file', function () {
        $user = makeUser();

        $response = importVcf($this, $user, vcfFile([davVcard('a', 'Jane Doe'), davVcard('b', 'John Doe')], 'Friends 2030.vcf'));

        expectApiSuccess($response, 200);
        $addressBook = addressBookNamed($this, $user, 'Friends 2030');
        expect($addressBook)->not->toBeNull()
            ->and($addressBook['color'])->toBe('#0088CC')
            ->and(storedCards($addressBook['id']))->toHaveCount(2);
        expect(contactsImportState($this, $user))->toMatchArray([
            'stage' => 'finished',
            'source' => 'file',
            'address_books' => null,
            'address_books_count' => 1,
            'address_books_done' => 1,
            'address_books_current' => 'Friends 2030',
            'contacts_count' => 2,
            'contacts_done' => 2,
            'contacts_skipped' => 0,
        ]);

        $names = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/address-books/contacts')->json('data'))->pluck('full_name');
        expect($names)->toContain('Jane Doe', 'John Doe');
    });

    it('answers with the state of the queued import', function () {
        $user = makeUser();
        Queue::fake();

        $response = importVcf($this, $user, vcfFile([davVcard('a'), davVcard('b')], 'Friends 2030.vcf'));

        expectApiSuccess($response, 200);
        expect($response->json('data'))->toBe([
            'stage' => 'started',
            'source' => 'file',
            'address_books' => null,
            'address_books_count' => 1,
            'address_books_done' => 0,
            'address_books_current' => 'Friends 2030',
            'contacts_count' => 2,
            'contacts_done' => 0,
            'contacts_skipped' => 0,
        ]);
        Queue::assertPushed(ImportContactsFile::class, fn (ImportContactsFile $job) => $job->userId === $user->id);
    });

    it('uses the given name and color and numbers a name that is taken', function () {
        $user = makeUser();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/address-books', ['name' => 'Work'])->assertStatus(201);

        expectApiSuccess(importVcf($this, $user, vcfFile([davVcard('a')]), ['name' => 'Work', 'color' => '#FF0000']), 200);

        $addressBook = addressBookNamed($this, $user, 'Work (1)');
        expect($addressBook)->not->toBeNull()
            ->and($addressBook['color'])->toBe('#FF0000')
            ->and(storedCards($addressBook['id']))->toHaveCount(1);
    });

    it('numbers a name whose address book URI is taken by a differently written name', function () {
        $user = makeUser();

        expectApiSuccess(importVcf($this, $user, vcfFile([davVcard('a')], 'contacts.vcf')), 200);

        $addressBook = addressBookNamed($this, $user, 'contacts (1)');
        expect($addressBook)->not->toBeNull()
            ->and(storedCards($addressBook['id']))->toHaveCount(1)
            ->and(contactsImportState($this, $user)['stage'])->toBe('finished');
    });

    it('imports into an existing address book of the user', function () {
        $user = makeUser();
        $addressBook = $this->actingAs($user, 'sanctum')->getJson('/api/v1/address-books')->json('data')[0];

        expectApiSuccess(importVcf($this, $user, vcfFile([davVcard('a')]), ['address_book_id' => $addressBook['id']]), 200);

        expect(storedCards($addressBook['id']))->toHaveCount(1)
            ->and($this->actingAs($user, 'sanctum')->getJson('/api/v1/address-books')->json('data'))->toHaveCount(1)
            ->and(contactsImportState($this, $user))->toMatchArray(['stage' => 'finished', 'address_books_count' => 0, 'address_books_current' => $addressBook['name'], 'contacts_done' => 1]);
    });

    it('keeps the uploaded file only until the import is done', function () {
        $user = makeUser();

        importVcf($this, $user, vcfFile([davVcard('a')]))->assertStatus(200);

        expect(Storage::disk('local')->allFiles())->toBe([]);
    });
});

describe('Contacts file import targets', function () {
    it('does not import into another user\'s address book', function () {
        $user = makeUser();
        $other = makeUser();
        $otherAddressBook = $this->actingAs($other, 'sanctum')->getJson('/api/v1/address-books')->json('data')[0];

        expectApiError(importVcf($this, $user, vcfFile([davVcard('a')]), ['address_book_id' => $otherAddressBook['id']]), 404);

        expect(storedCards($otherAddressBook['id']))->toBe([])
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('answers 404 for an address book that does not exist', function () {
        expectApiError(importVcf($this, makeUser(), vcfFile([davVcard('a')]), ['address_book_id' => 999999]), 404);
    });
});

describe('Contacts file import validation', function () {
    it('rejects a request that does not fit the rules', function (array $fields, string $field) {
        $user = makeUser();

        $response = importVcf($this, $user, $fields['file'] ?? vcfFile([davVcard('a')]), $fields);

        expectApiError($response, 422);
        expect($response->json('errors'))->toHaveKey($field);
    })->with([
        'no file' => [['file' => ''], 'file'],
        'not a vcf' => [['file' => UploadedFile::fake()->createWithContent('friends.csv', "name\nJane")], 'file'],
        'over 25 MB' => [['file' => UploadedFile::fake()->create('friends.vcf', 25601)], 'file'],
        'address book and name' => [['address_book_id' => 1, 'name' => 'Friends'], 'address_book_id'],
        'address book and color' => [['address_book_id' => 1, 'color' => '#FF0000'], 'address_book_id'],
        'address book not a number' => [['address_book_id' => 'abc'], 'address_book_id'],
        'color over seven characters' => [['color' => '#FF0000AA'], 'color'],
        'color not a hex value' => [['color' => 'red'], 'color'],
    ]);

    it('rejects a file without contacts without field errors', function (string $content) {
        $user = makeUser();

        $response = importVcf($this, $user, UploadedFile::fake()->createWithContent('friends.vcf', $content));

        expectApiError($response, 422);
        expect($response->json('errors'))->toBeNull()
            ->and($response->json('message'))->toBe('This file contains no contacts.')
            ->and($this->actingAs($user, 'sanctum')->getJson('/api/v1/address-books')->json('data'))->toHaveCount(1)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    })->with([
        'empty' => [''],
        'not a vCard' => ["\x89PNG\r\n\x1a\n binary"],
        'only a calendar' => ["BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"],
    ]);

    it('rejects a file with more contacts than the limit', function () {
        $cards = array_fill(0, ContactImport::MAX_FILE_CONTACTS + 1, "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nEND:VCARD\r\n");

        $response = importVcf($this, makeUser(), vcfFile($cards));

        expectApiError($response, 422);
        expect($response->json('message'))->toBe('This file contains more than 10000 contacts.');
    });
});

describe('Contacts file import card checks', function () {
    it('gives a card without UID a UID and a card without FN a name', function () {
        $user = makeUser();
        $cards = [
            "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:No Uid\r\nEND:VCARD\r\n",
            "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:no-fn\r\nN:Lovelace;Ada;;;\r\nEND:VCARD\r\n",
        ];

        importVcf($this, $user, vcfFile($cards))->assertStatus(200);

        $stored = cardsByName(addressBookNamed($this, $user, 'friends')['id']);
        expect(array_keys($stored))->toEqualCanonicalizing(['No Uid', 'Ada Lovelace'])
            ->and((string) Reader::read($stored['No Uid'])->UID)->not->toBe('')
            ->and((string) Reader::read($stored['Ada Lovelace'])->UID)->toBe('no-fn');
    });

    it('skips a broken card among good ones and never stores it', function () {
        $user = makeUser();
        $cards = [
            davVcard('a', 'Jane Doe'),
            "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:broken\r\nFN:Broken\r\nitem_1.EMAIL:broken@example.com\r\nEND:VCARD\r\n",
            "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:nameless\r\nTEL:+1 555 0100\r\nEND:VCARD\r\n",
            davVcard('b', 'John Doe'),
        ];

        importVcf($this, $user, vcfFile($cards))->assertStatus(200);

        expect(array_keys(cardsByName(addressBookNamed($this, $user, 'friends')['id'])))->toEqualCanonicalizing(['Jane Doe', 'John Doe'])
            ->and(contactsImportState($this, $user))->toMatchArray(['stage' => 'finished', 'contacts_count' => 4, 'contacts_done' => 2, 'contacts_skipped' => 2]);
    });

    it('keeps the cards after a card without END:VCARD and counts that card', function (string $position, string $cutCard, array $imported, int $skipped) {
        $user = makeUser();
        $cards = [davVcard('a', 'Jane Doe'), davVcard('b', 'John Doe'), davVcard('c', 'Max Doe')];
        array_splice($cards, $position === 'middle' ? 1 : 3, 0, [$cutCard]);

        importVcf($this, $user, vcfFile($cards))->assertStatus(200);

        expect(array_keys(cardsByName(addressBookNamed($this, $user, 'friends')['id'])))->toEqualCanonicalizing($imported)
            ->and(contactsImportState($this, $user))->toMatchArray(['stage' => 'finished', 'contacts_count' => 4, 'contacts_done' => count($imported), 'contacts_skipped' => $skipped]);
    })->with([
        'readable, in the middle' => ['middle', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:cut\r\nFN:Cut Off\r\n", ['Jane Doe', 'Cut Off', 'John Doe', 'Max Doe'], 0],
        'readable, at the end' => ['end', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:cut\r\nFN:Cut Off\r\n", ['Jane Doe', 'John Doe', 'Max Doe', 'Cut Off'], 0],
        'unreadable, in the middle' => ['middle', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:cut\r\nitem_1.EMAIL:cut@example.com\r\n", ['Jane Doe', 'John Doe', 'Max Doe'], 1],
        'unreadable, at the end' => ['end', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:cut\r\nitem_1.EMAIL:cut@example.com\r\n", ['Jane Doe', 'John Doe', 'Max Doe'], 1],
    ]);

    it('keeps a vCard 2.1 agent card inside its owner', function () {
        $user = makeUser();
        $owner = "BEGIN:VCARD\r\nVERSION:2.1\r\nN:Doe;Jane;;;\r\nFN:Jane Doe\r\nAGENT:\r\nBEGIN:VCARD\r\nVERSION:2.1\r\nN:Agent;Smith;;;\r\nFN:Smith Agent\r\nEND:VCARD\r\nEND:VCARD\r\n";

        importVcf($this, $user, vcfFile([$owner, davVcard('b', 'John Doe')]))->assertStatus(200);

        expect(contactsImportState($this, $user))->toMatchArray(['contacts_count' => 2])
            ->and(contactsImportState($this, $user)['contacts_done'] + contactsImportState($this, $user)['contacts_skipped'])->toBe(2)
            ->and(array_keys(cardsByName(addressBookNamed($this, $user, 'friends')['id'])))->not->toContain('Smith Agent');
    });

    it('gives a card a new UID when the UID is already in the address book', function () {
        $user = makeUser();
        $addressBook = $this->actingAs($user, 'sanctum')->getJson('/api/v1/address-books')->json('data')[0];
        $file = fn () => vcfFile([davVcard('same', 'Jane Doe'), davVcard('same', 'Jane Copy')]);

        importVcf($this, $user, $file(), ['address_book_id' => $addressBook['id']])->assertStatus(200);
        importVcf($this, $user, $file(), ['address_book_id' => $addressBook['id']])->assertStatus(200);

        $uids = collect(storedCards($addressBook['id']))->map(fn (string $card) => (string) Reader::read($card)->UID);
        expect($uids)->toHaveCount(4)
            ->and($uids->unique())->toHaveCount(4)
            ->and($uids)->toContain('same');
    });

    it('stores a card with a photo', function () {
        $user = makeUser();

        importVcf($this, $user, vcfFixture('apple-export.vcf'))->assertStatus(200);

        $stored = cardsByName(addressBookNamed($this, $user, 'apple-export')['id']);
        expect(Reader::read($stored['Dr. Jürgen Müller'])->PHOTO)->not->toBeNull();
    });

    it('passes every imported card through the photo processor', function () {
        $user = makeUser();
        $spy = photoProcessorSpy();

        importVcf($this, $user, vcfFile([davVcard('a', 'Jane Doe'), davVcard('b', 'John Doe')]))->assertStatus(200);

        expect($spy->cards)->toHaveCount(2)
            ->and($spy->cards[0])->toContain('FN:Jane Doe')
            ->and($spy->cards[1])->toContain('FN:John Doe');
    });

    it('stores a card that needs no change as written, commas in URI values included', function () {
        $user = makeUser();
        photoProcessorSpy();
        $card = davVcard4('v4', 'Jane Comma', ['PHOTO:data:image/png;base64,iVBORw0KGgo=', 'URL:https://example.org/a,b']);

        importVcf($this, $user, vcfFile([$card]))->assertStatus(200);

        $stored = storedCards(addressBookNamed($this, $user, 'friends')['id']);
        expect($stored)->toBe([$card])
            ->and((string) Reader::read($stored[0])->URL)->toBe('https://example.org/a,b');
    });

    it('re-serialises a card it had to change', function () {
        $user = makeUser();
        photoProcessorSpy();
        $card = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:No Uid\r\nURL:https://example.org/a,b\r\nEND:VCARD\r\n";

        importVcf($this, $user, vcfFile([$card]))->assertStatus(200);

        $stored = storedCards(addressBookNamed($this, $user, 'friends')['id']);
        expect($stored)->toHaveCount(1)
            ->and($stored[0])->not->toBe($card)
            ->and((string) Reader::read($stored[0])->UID)->not->toBe('');
    });
});

describe('Contacts file import from other apps', function () {
    it('imports every contact of an export', function (string $fixture, array $names, string $version) {
        $user = makeUser();

        importVcf($this, $user, vcfFixture($fixture))->assertStatus(200);

        $addressBook = addressBookNamed($this, $user, pathinfo($fixture, PATHINFO_FILENAME));
        $stored = cardsByName($addressBook['id']);
        expect(array_keys($stored))->toEqualCanonicalizing($names)
            ->and(contactsImportState($this, $user)['contacts_skipped'])->toBe(0);

        foreach ($stored as $card) {
            $vCard = Reader::read($card);
            expect((string) $vCard->VERSION)->toBe($version)
                ->and((string) $vCard->UID)->not->toBe('');
        }
    })->with([
        'Google' => ['google-export.vcf', ['Sam O\'Neil', 'Priya Raman', '+49 30 1234567'], '3.0'],
        'Apple' => ['apple-export.vcf', ['Dr. Jürgen Müller', 'Johnny Appleseed'], '3.0'],
        'Android (vCard 2.1)' => ['android-export-v21.vcf', ['Jürgen Müller', 'Jane Doe', ''], '3.0'],
        'Thunderbird (vCard 4.0)' => ['thunderbird-export-v4.vcf', ['Ana María García', 'Liu Wei'], '4.0'],
        'Nextcloud' => ['nextcloud-export.vcf', ['Olga Petrova', 'Kenji Sato'], '3.0'],
    ]);

    it('converts a vCard 2.1 card to 3.0 with its encoded fields and photo', function () {
        $user = makeUser();

        importVcf($this, $user, vcfFixture('android-export-v21.vcf'))->assertStatus(200);

        $vCard = Reader::read(cardsByName(addressBookNamed($this, $user, 'android-export-v21')['id'])['Jürgen Müller']);
        expect((string) $vCard->N)->toBe('Müller;Jürgen;;;')
            ->and($vCard->serialize())->not->toContain('CHARSET')
            ->and((string) $vCard->PHOTO['ENCODING'])->toBe('b')
            ->and((string) $vCard->ADR)->toContain('Hauptstraße 5;München')
            ->and((string) $vCard->TEL)->toBe('+491701234567')
            ->and(getimagesizefromstring($vCard->PHOTO->getValue()))->not->toBeFalse();
    });

    it('keeps the contacts of solyto\'s own export', function () {
        $user = makeUser();
        $addressBook = $this->actingAs($user, 'sanctum')->getJson('/api/v1/address-books')->json('data')[0];
        importVcf($this, $user, vcfFixture('google-export.vcf'), ['address_book_id' => $addressBook['id']])->assertStatus(200);
        importVcf($this, $user, vcfFixture('apple-export.vcf'), ['address_book_id' => $addressBook['id']])->assertStatus(200);
        $path = tempnam(sys_get_temp_dir(), 'contacts');
        app(ContactExportService::class)->export($user, $path);

        importVcf($this, $user, UploadedFile::fake()->createWithContent('contacts.vcf', file_get_contents($path)), ['name' => 'Restored'])->assertStatus(200);
        unlink($path);

        $original = cardsByName($addressBook['id']);
        $restored = cardsByName(addressBookNamed($this, $user, 'Restored')['id']);
        expect(array_keys($restored))->toEqualCanonicalizing(array_keys($original));

        foreach ($original as $name => $card) {
            expect(Reader::read($restored[$name])->serialize())->toBe(Reader::read($card)->serialize());
        }
    });

    it('answers addressbook-multiget for the imported address book', function () {
        $user = davUser();
        importVcf($this, $user, vcfFixture('android-export-v21.vcf'))->assertStatus(200);
        importVcf($this, $user, vcfFixture('apple-export.vcf'))->assertStatus(200);
        $addressBook = addressBookNamed($this, $user, 'android-export-v21');
        $uri = DB::connection('pgsql')->table('addressbooks')->where('id', $addressBook['id'])->value('uri');
        $path = "addressbooks/{$user->email}/{$uri}/";
        $hrefs = DB::connection('pgsql')->table('cards')->where('addressbookid', $addressBook['id'])->pluck('uri')
            ->map(fn (string $card) => "<d:href>/{$path}{$card}</d:href>")->implode('');

        $response = davRequest($user, 'REPORT', $path, '<?xml version="1.0" encoding="utf-8"?>'
            .'<card:addressbook-multiget xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            ."<d:prop><d:getetag/><card:address-data/></d:prop>{$hrefs}"
            .'</card:addressbook-multiget>', ['Depth' => '1']);

        expect($response->getStatus())->toBe(207)
            ->and(davXpath($response)->evaluate('count(//card:address-data)'))->toBe(3.0)
            ->and(davXpath($response)->evaluate('string(//d:multistatus)'))->toContain('FN:Jürgen Müller');
    });
});

describe('Contacts file import while another import runs', function () {
    it('answers 409 to a second import and keeps the first one\'s state', function () {
        $user = makeUser();
        Queue::fake();
        importVcf($this, $user, vcfFile([davVcard('a')]))->assertStatus(200);

        expectApiError(importVcf($this, $user, vcfFile([davVcard('b')], 'other.vcf')), 409);

        expect(contactsImportState($this, $user)['address_books_current'])->toBe('friends')
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('answers 409 to a CardDAV import while a file import runs', function () {
        $user = makeUser();
        Queue::fake();
        importVcf($this, $user, vcfFile([davVcard('a')]))->assertStatus(200);

        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/address-books/import', [
            'url' => 'https://remote.example.com/remote.php/dav/',
            'username' => 'alice',
            'secret' => 'app-password',
        ]), 409);
    });

    it('lets a new import start once the running one has stalled', function () {
        $user = makeUser();
        Queue::fake();
        importVcf($this, $user, vcfFile([davVcard('a')]))->assertStatus(200);

        $this->travel(6)->minutes();

        expectApiSuccess(importVcf($this, $user, vcfFile([davVcard('b')], 'other.vcf')), 200);
    });

    it('does not count a state saved before import progress was timestamped as running', function () {
        $user = makeUser();
        Cache::set('contact-import-'.$user->id, tap(new AddressBookImportDTO(), function ($dto) use ($user) {
            $dto->jobId = 'old-job';
            $dto->userEmail = $user->email;
            $dto->source = 'file';
            $dto->stage = 'contacts';
        }));

        expectApiSuccess(importVcf($this, $user, vcfFile([davVcard('a')])), 200);
    });

    it('does not count a finished import as running', function () {
        $user = makeUser();
        importVcf($this, $user, vcfFile([davVcard('a')]))->assertStatus(200);

        expectApiSuccess(importVcf($this, $user, vcfFile([davVcard('b')], 'other.vcf')), 200);
    });
});

describe('Contacts file import job', function () {
    it('marks the import failed and deletes the file when the job fails', function () {
        $user = makeUser();
        Queue::fake();
        importVcf($this, $user, vcfFile([davVcard('a')]))->assertStatus(200);
        $jobId = app(DavService::class)->import()->addressBooks()->getState($user->id)->jobId;

        (new ImportContactsFile($user->id, $jobId))->failed(new RuntimeException('Timed out'));

        expect(contactsImportState($this, $user)['stage'])->toBe('failed')
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('leaves a newer import alone when a stale job runs', function () {
        $user = makeUser();
        Queue::fake();
        importVcf($this, $user, vcfFile([davVcard('a')]))->assertStatus(200);
        $state = app(DavService::class)->import()->addressBooks()->getState($user->id);

        (new ImportContactsFile($user->id, 'an-older-job'))->handle(app(DavService::class));
        (new ImportContactsFile($user->id, 'an-older-job'))->failed(null);

        expect(contactsImportState($this, $user)['stage'])->toBe('started')
            ->and(Storage::disk('local')->exists("contact-imports/{$state->jobId}.vcf"))->toBeTrue();
    });

    it('fails the import when the target address book is gone', function () {
        $user = makeUser();
        Queue::fake();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/address-books', ['name' => 'Work'])->assertStatus(201);
        $addressBook = addressBookNamed($this, $user, 'Work');
        importVcf($this, $user, vcfFile([davVcard('a')]), ['address_book_id' => $addressBook['id']])->assertStatus(200);
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/address-books/{$addressBook['id']}")->assertStatus(200);
        $state = app(DavService::class)->import()->addressBooks()->getState($user->id);

        expect(fn () => (new ImportContactsFile($user->id, $state->jobId))->handle(app(DavService::class)))
            ->toThrow(ImportFileException::class);
        expect(Storage::disk('local')->allFiles())->toBe([]);
    });
});
