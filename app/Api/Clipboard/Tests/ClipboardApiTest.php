<?php

use App\Api\Clipboard\Models\Clipboard;
use App\Api\Clipboard\Services\ClipboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

describe('Clipboard list and store', function () {
    it('lists the users clipboard entries newest first', function () {
        $user = makeUser();
        Clipboard::factory()->forUser($user)->count(2)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/clipboard')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    });

    it('stores a text clipboard entry', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/clipboard', ['content' => 'Some copied text'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect(Clipboard::where('user_id', $user->id)->where('type', 'text')->count())->toBe(1);
    });

    it('deletes a clipboard entry', function () {
        $user = makeUser();
        $clipboard = Clipboard::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/clipboard/'.$clipboard->id)
            ->assertStatus(200);

        expect(Clipboard::count())->toBe(0);
    });

    it('forbids deleting another users entry', function () {
        $user = makeUser();
        $other = makeUser();
        $clipboard = Clipboard::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/clipboard/'.$clipboard->id)
            ->assertStatus(403);
    });
});

describe('Clipboard images', function () {
    it('stores an image and serves it back', function () {
        Storage::fake('user_data');
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/clipboard/image', [
                'image' => UploadedFile::fake()->image('photo.png', 200, 200),
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $clipboardId = $store->json('data.id');
        $clipboard = Clipboard::find($clipboardId);

        expect($clipboard->type)->toBe('image');
        expect($clipboard->file_path)->not->toBeNull();
        Storage::disk('user_data')->assertExists($clipboard->file_path);

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/clipboard/'.$clipboardId.'/image')
            ->assertStatus(200);
    });

    it('returns 404 when requesting an image for a text entry', function () {
        $user = makeUser();
        $clipboard = Clipboard::factory()->forUser($user)->create(['type' => 'text']);

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/clipboard/'.$clipboard->id.'/image')
            ->assertStatus(404);
    });

    it('deletes the stored image file with the entry', function () {
        Storage::fake('user_data');
        $user = makeUser();

        Storage::disk('user_data')->put('clipboard/image.png', 'fake-bytes');

        $clipboard = Clipboard::factory()->forUser($user)->create([
            'type' => 'image',
            'file_path' => 'clipboard/image.png',
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/clipboard/'.$clipboard->id)
            ->assertStatus(200);

        Storage::disk('user_data')->assertMissing('clipboard/image.png');
    });
});

describe('ClipboardService', function () {
    it('storeImage creates an image entry', function () {
        Storage::fake('user_data');
        $user = makeUser();

        $clipboard = app(ClipboardService::class)->storeImage(
            $user,
            UploadedFile::fake()->image('photo.png', 100, 100)
        );

        expect($clipboard)->not->toBeNull();
        expect($clipboard->type)->toBe('image');
    });

    it('storeImage returns null for invalid images', function () {
        Storage::fake('user_data');
        $user = makeUser();

        $clipboard = app(ClipboardService::class)->storeImage(
            $user,
            UploadedFile::fake()->create('doc.pdf', 100)
        );

        expect($clipboard)->toBeNull();
    });

    it('getImagePath returns null for non-image entries', function () {
        $user = makeUser();
        $clipboard = Clipboard::factory()->forUser($user)->create(['type' => 'text']);

        expect(app(ClipboardService::class)->getImagePath($user->id, $clipboard))->toBeNull();
    });
});
