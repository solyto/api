<?php

use App\Api\Tags\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Tag CRUD', function () {
    it('lists, stores, shows, updates and deletes tags', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tags')
            ->assertStatus(200)->assertJsonCount(0, 'data');

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/tags', ['name' => 'Work', 'color' => '#FF0000'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Work');

        $tagId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tags/'.$tagId)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $tagId);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/tags/'.$tagId, ['name' => 'Office'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Office');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tags/'.$tagId)
            ->assertStatus(200);

        expect(Tag::count())->toBe(0);
    });

    it('scopes tags to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        $tag = Tag::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tags')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tags/'.$tag->id)
            ->assertStatus(403);
    });
});
