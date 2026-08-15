<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
