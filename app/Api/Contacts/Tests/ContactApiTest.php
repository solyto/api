<?php

use App\Api\Contacts\Services\ContactService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

function expectAddressBookNotFound($response): void
{
    expectApiError($response, 404);
    $response->assertJsonPath('message', 'Address Book does not exist');
}

function expectContactNotFound($response): void
{
    expectApiError($response, 404);
    $response->assertJsonPath('message', 'Contact does not exist');
}

function contactPhoto(): UploadedFile
{
    return UploadedFile::fake()->image('photo.png', 100, 100);
}

describe('Address books (DAV-backed)', function () {
    it('lists, stores, updates and deletes address books', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Personal'])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $addressBookId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/address-books')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/address-books/'.$addressBookId, ['name' => 'Friends'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId)
            ->assertStatus(200);
    });

    it('rejects an address book color that cannot be stored', function () {
        $user = makeUser();

        $addressBookId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Personal'])
            ->assertStatus(201)
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/address-books/'.$addressBookId, ['color' => '#FF0000AA'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('color');
    });

    // App builds up to 1.1.4 send this for an address book without a colour on every click outside its row.
    it('accepts the old builds\' no-colour value and keeps the colour', function () {
        $user = makeUser();

        $addressBookId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Personal'])
            ->json('data.id');
        $before = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/address-books')->json('data'))->firstWhere('id', $addressBookId)['color'];

        expectApiSuccess($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/address-books/'.$addressBookId, ['color' => ContactService::LEGACY_NO_COLOR]), 200);

        expect(collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/address-books')->json('data'))->firstWhere('id', $addressBookId)['color'])->toBe($before);
    });

    it('caps a generated address book uri and keeps it unique', function () {
        $user = makeUser();
        $principal = 'principals/'.$user->email;
        $longName = str_repeat('a', 240);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => $longName])
            ->assertStatus(201);

        $longUri = DB::connection('pgsql')->table('addressbooks')
            ->where('principaluri', $principal)
            ->where('displayname', $longName)
            ->value('uri');

        expect($longUri)->not->toBeNull()
            ->and(strlen($longUri))->toBeLessThanOrEqual(200);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/address-books', ['name' => '😀'])->assertStatus(201);

        expect(DB::connection('pgsql')->table('addressbooks')
            ->where('principaluri', $principal)
            ->where('displayname', '😀')
            ->value('uri'))->toMatch('/^[0-9a-f-]{36}$/');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/address-books', ['name' => 'Work'])->assertStatus(201);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/address-books', ['name' => 'work!'])->assertStatus(201);

        $uris = DB::connection('pgsql')->table('addressbooks')
            ->where('principaluri', $principal)
            ->whereIn('displayname', ['Work', 'work!'])
            ->pluck('uri')
            ->all();

        expect($uris)->toHaveCount(2)
            ->and(array_unique($uris))->toHaveCount(2);
    });

    it('rejects an address book with an existing name with 409', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Personal'])
            ->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/address-books', ['name' => 'Personal']);

        expectApiError($response, 409);
        $response->assertJsonPath('message', 'Address Book already exists.');
    });

    it('lists contacts from all address books', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/address-books/contacts')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('stores, updates and deletes contacts', function () {
        $user = makeUser();

        $addressBook = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Work'])
            ->json('data');
        $addressBookId = $addressBook['id'];

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books/'.$addressBookId.'/contacts', [
                'address_book_id' => $addressBookId,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => json_encode([['value' => 'john@example.com', 'type' => 'INTERNET']]),
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $contactUri = $store->json('data.uri');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contactUri, [
                'first_name' => 'Johnny',
                'address_book_id' => $addressBookId,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contactUri)
            ->assertStatus(200);
    });
});

describe('Contact photos', function () {
    it('flags contacts with a photo in the list and still serves the photo', function () {
        $user = makeUser();

        $addressBookId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Family'])
            ->json('data.id');

        $createContact = fn (string $firstName) => $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books/'.$addressBookId.'/contacts', [
                'address_book_id' => $addressBookId,
                'first_name' => $firstName,
                'last_name' => 'Roe',
            ])
            ->json('data.uri');
        $withPhoto = $createContact('Jane');
        $withoutPhoto = $createContact('John');

        $listHasPhoto = fn () => collect($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/address-books/contacts')
            ->assertStatus(200)
            ->json('data'))->pluck('has_photo', 'uri')->sortKeys()->all(); // the list order isn't fixed

        expect($listHasPhoto())->toBe(collect([$withPhoto => false, $withoutPhoto => false])->sortKeys()->all());

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/address-books/'.$addressBookId.'/contacts/'.$withPhoto.'/photo', [
                'photo' => contactPhoto(),
            ])
            ->assertStatus(200);

        expect($listHasPhoto())->toBe(collect([$withPhoto => true, $withoutPhoto => false])->sortKeys()->all());

        $photos = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books/contacts/photos', [
                'contacts' => [
                    ['address_book_id' => $addressBookId, 'uri' => $withPhoto],
                    ['address_book_id' => $addressBookId, 'uri' => $withoutPhoto],
                ],
            ])
            ->assertStatus(200)
            ->json('data');
        expect(array_keys($photos))->toBe([$withPhoto])
            ->and($photos[$withPhoto])->not->toBeEmpty();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$withPhoto.'/photo')
            ->assertStatus(200);

        expect($listHasPhoto())->toBe(collect([$withPhoto => false, $withoutPhoto => false])->sortKeys()->all());
    });

    it('adds and removes a contact photo', function () {
        $user = makeUser();

        $addressBook = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Family'])
            ->json('data');
        $addressBookId = $addressBook['id'];

        $contact = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books/'.$addressBookId.'/contacts', [
                'address_book_id' => $addressBookId,
                'first_name' => 'Jane',
                'last_name' => 'Roe',
            ])
            ->json('data');

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contact['uri'].'/photo', [
                'photo' => contactPhoto(),
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contact['uri'].'/photo')
            ->assertStatus(200);
    });
});

describe('Contact tenant isolation', function () {
    it('hides another user\'s address book and contacts and denies writes to them', function () {
        $user = makeUser();
        $other = makeUser();

        $addressBookId = $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Private'])
            ->assertStatus(201)
            ->json('data.id');

        $contactUri = $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/address-books/'.$addressBookId.'/contacts', [
                'address_book_id' => $addressBookId,
                'first_name' => 'Secret',
                'last_name' => 'Person',
            ])
            ->assertStatus(201)
            ->json('data.uri');

        $this->actingAs($other, 'sanctum')
            ->post('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contactUri.'/photo', [
                'photo' => contactPhoto(),
            ])
            ->assertStatus(200);

        $addressBookIds = collect($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/address-books')
            ->assertStatus(200)
            ->json('data'))->pluck('id');
        expect($addressBookIds)->not->toContain($addressBookId);

        $contactUris = collect($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/address-books/contacts')
            ->assertStatus(200)
            ->json('data'))->pluck('uri');
        expect($contactUris)->not->toContain($contactUri);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books/contacts/photos', [
                'contacts' => [['address_book_id' => $addressBookId, 'uri' => $contactUri]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data', []);

        // The module answers an address book the user cannot see with 404.
        expectAddressBookNotFound($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/address-books/'.$addressBookId, ['color' => '#000000']));

        expectAddressBookNotFound($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId));

        expectAddressBookNotFound($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books/'.$addressBookId.'/contacts', [
                'address_book_id' => $addressBookId,
                'first_name' => 'Intruder',
            ]));

        expectAddressBookNotFound($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contactUri, [
                'address_book_id' => $addressBookId,
                'first_name' => 'Hijacked',
            ]));

        expectAddressBookNotFound($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contactUri));

        expectAddressBookNotFound($this->actingAs($user, 'sanctum')
            ->post('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contactUri.'/photo', [
                'photo' => contactPhoto(),
            ]));

        expectAddressBookNotFound($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contactUri.'/photo'));

        $otherContacts = collect($this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/address-books/contacts')
            ->json('data'));
        expect($otherContacts->pluck('uri'))->toContain($contactUri);
        expect($otherContacts->firstWhere('uri', $contactUri)['first_name'])->toBe('Secret');
    });

    it('answers a contact that does not exist in an own address book with 404', function () {
        $user = makeUser();

        $addressBookId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Personal'])
            ->assertStatus(201)
            ->json('data.id');

        $missingUri = 'missing-contact.vcf';

        expectContactNotFound($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$missingUri, [
                'address_book_id' => $addressBookId,
                'first_name' => 'Nobody',
            ]));

        expectContactNotFound($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$missingUri));

        expectContactNotFound($this->actingAs($user, 'sanctum')
            ->post('/api/v1/address-books/'.$addressBookId.'/contacts/'.$missingUri.'/photo', [
                'photo' => contactPhoto(),
            ]));

        expectContactNotFound($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$missingUri.'/photo'));
    });
});
