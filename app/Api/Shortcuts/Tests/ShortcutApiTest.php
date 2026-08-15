<?php

use App\Api\Shortcuts\Models\Shortcut;
use App\Api\Shortcuts\Services\ShortcutService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Shortcut CRUD', function () {
    it('lists, stores, shows, updates and deletes shortcuts', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/shortcuts', ['title' => 'GitHub', 'url' => 'https://github.com'])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'GitHub');

        $shortcutId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/shortcuts')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/shortcuts/'.$shortcutId)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $shortcutId);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/shortcuts/'.$shortcutId, ['title' => 'GitLab'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'GitLab');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/shortcuts/'.$shortcutId)
            ->assertStatus(200);

        expect(Shortcut::count())->toBe(0);
    });

    it('reorders shortcuts', function () {
        $user = makeUser();
        $a = Shortcut::factory()->forUser($user)->create(['order' => 0]);
        $b = Shortcut::factory()->forUser($user)->create(['order' => 1]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/shortcuts/reorder', [
                'shortcuts' => [$b->id, $a->id],
            ])
            ->assertStatus(200);

        expect($a->fresh()->order)->toBe(1);
        expect($b->fresh()->order)->toBe(0);
    });

    it('scopes shortcuts to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        $shortcut = Shortcut::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/shortcuts/'.$shortcut->id)
            ->assertStatus(403);
    });
});

describe('ShortcutService', function () {
    it('reorder only updates the owning users shortcuts', function () {
        $user = makeUser();
        $other = makeUser();
        $own = Shortcut::factory()->forUser($user)->create(['order' => 0]);
        $foreign = Shortcut::factory()->forUser($other)->create(['order' => 5]);

        app(ShortcutService::class)->reorder($user, [$own->id]);

        expect($own->fresh()->order)->toBe(0);
        expect($foreign->fresh()->order)->toBe(5);
    });
});
