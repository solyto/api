<?php


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

    it('rejects an address book with an existing name with 409', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Personal'])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books', ['name' => 'Personal'])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Address Book already exists.');
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
                'photo' => \Illuminate\Http\UploadedFile::fake()->image('photo.png', 100, 100),
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
                'photo' => \Illuminate\Http\UploadedFile::fake()->image('photo.png', 100, 100),
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
        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/address-books/'.$addressBookId, ['color' => '#000000']), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/address-books/'.$addressBookId.'/contacts', [
                'address_book_id' => $addressBookId,
                'first_name' => 'Intruder',
            ]), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contactUri, [
                'address_book_id' => $addressBookId,
                'first_name' => 'Hijacked',
            ]), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contactUri), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->post('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contactUri.'/photo', [
                'photo' => \Illuminate\Http\UploadedFile::fake()->image('photo.png', 100, 100),
            ]), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/address-books/'.$addressBookId.'/contacts/'.$contactUri.'/photo'), 404);

        $otherContacts = collect($this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/address-books/contacts')
            ->json('data'));
        expect($otherContacts->pluck('uri'))->toContain($contactUri);
        expect($otherContacts->firstWhere('uri', $contactUri)['first_name'])->toBe('Secret');
    });
});
