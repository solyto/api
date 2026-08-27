<?php

use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Notes\Models\Note;
use App\Api\Todos\Models\Todo;
use App\Shared\Services\QuickAddService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

describe('QuickAddService::detect', function () {
    it('detects a todo from todo keywords', function () {
        $result = app(QuickAddService::class)->detect('Buy milk due:tomorrow');

        expect($result->contentType)->toBe(QuickAddContentType::Todo);
        expect($result->confidence)->toBe(0.70);
    });

    it('detects a todo from a leading slash or hash', function () {
        $result = app(QuickAddService::class)->detect('/work Plan sprint');

        expect($result->contentType)->toBe(QuickAddContentType::Todo);
    });

    it('detects music from a deezer url', function () {
        $result = app(QuickAddService::class)->detect('https://www.deezer.com/album/12345');

        expect($result->contentType)->toBe(QuickAddContentType::Music);
        expect($result->confidence)->toBe(0.95);
        expect($result->needsConfirmation)->toBeFalse();
    });

    it('detects music from a spotify url', function () {
        $result = app(QuickAddService::class)->detect('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy');

        expect($result->contentType)->toBe(QuickAddContentType::Music);
        expect($result->confidence)->toBe(0.95);
        expect($result->needsConfirmation)->toBeFalse();
    });

    it('detects books from a hardcover url', function () {
        $result = app(QuickAddService::class)->detect('https://hardcover.app/books/dune');

        expect($result->contentType)->toBe(QuickAddContentType::Books);
    });

    it('detects movies from an imdb url', function () {
        $result = app(QuickAddService::class)->detect('https://www.imdb.com/title/tt1160419/');

        expect($result->contentType)->toBe(QuickAddContentType::Movies);
    });

    it('detects games from a steam url', function () {
        $result = app(QuickAddService::class)->detect('https://store.steampowered.com/app/367520/');

        expect($result->contentType)->toBe(QuickAddContentType::Games);
    });

    it('detects recipes from a chefkoch url', function () {
        $result = app(QuickAddService::class)->detect('https://www.chefkoch.de/rezepte/12345');

        expect($result->contentType)->toBe(QuickAddContentType::Recipes);
    });

    it('falls back to links for other urls', function () {
        $result = app(QuickAddService::class)->detect('https://example.com/article');

        expect($result->contentType)->toBe(QuickAddContentType::Links);
    });

    it('falls back to notes for plain text', function () {
        $result = app(QuickAddService::class)->detect('Just a random thought');

        expect($result->contentType)->toBe(QuickAddContentType::Note);
        expect($result->needsConfirmation)->toBeTrue();
    });
});

describe('QuickAddService::commit', function () {
    it('commits a todo', function () {
        $user = makeUser();

        $todo = app(QuickAddService::class)->commit($user, 'Buy milk', QuickAddContentType::Todo, []);

        expect($todo)->toBeInstanceOf(Todo::class);
        expect(Todo::where('user_id', $user->id)->count())->toBe(1);
    });

    it('commits a note', function () {
        $user = makeUser();

        $note = app(QuickAddService::class)->commit($user, 'A quick note', QuickAddContentType::Note, []);

        expect($note)->toBeInstanceOf(Note::class);
        expect(Note::where('user_id', $user->id)->count())->toBe(1);
    });

    it('commits a spotify url as music', function () {
        $user = makeUser();

        config([
            'services.spotify.client_id' => 'test-client-id',
            'services.spotify.client_secret' => 'test-client-secret',
        ]);

        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response(['access_token' => 'tok123', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            'https://api.spotify.com/v1/albums/4aawyAB9vmqN3uQ7FjRGTy' => Http::response([
                'id' => '4aawyAB9vmqN3uQ7FjRGTy',
                'name' => 'OK Computer',
                'artists' => [['id' => '5K4W6rqBFWDnAN6FQUkS6x', 'name' => 'Radiohead']],
                'images' => [],
                'release_date' => '1997-05-21',
                'release_date_precision' => 'day',
                'album_type' => 'album',
                'genres' => ['alternative rock'],
            ]),
        ]);

        $music = app(QuickAddService::class)->commit(
            $user,
            'https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy',
            QuickAddContentType::Music,
            []
        );

        expect($music)->toBeInstanceOf(LibraryMusic::class);
        expect($music->title)->toBe('OK Computer');
        expect($music->artist)->toBe('Radiohead');
        expect($music->publication_year)->toBe(1997);
        expect(LibraryMusic::where('user_id', $user->id)->count())->toBe(1);
    });
});

describe('Quick-add endpoints', function () {
    it('detects the content type via the api', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dashboard/quick-add/detect', [
                'content' => 'https://hardcover.app/books/dune',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.content_type', 'books');
    });

    it('commits content via the api', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dashboard/quick-add/commit', [
                'content' => 'Plan weekend trip',
                'content_type' => 'todo',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect(Todo::where('user_id', $user->id)->count())->toBe(1);
    });

    it('rejects an invalid content type', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dashboard/quick-add/commit', [
                'content' => 'x',
                'content_type' => 'invalid',
            ])
            ->assertStatus(422);
    });
});
