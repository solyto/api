<?php

use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Api\Libraries\Models\LibraryGame;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Notes\Models\Note;
use App\Api\Todos\Models\Todo;
use App\Shared\Services\QuickAddService;
use Illuminate\Support\Facades\Http;

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

    it('commits a steam url as a game', function () {
        $user = makeUser();

        Http::fake([
            'https://store.steampowered.com/api/appdetails?appids=*' => Http::response([
                '367520' => [
                    'success' => true,
                    'data' => [
                        'type' => 'game',
                        'name' => 'Hollow Knight',
                        'developers' => ['Team Cherry'],
                        'publishers' => ['Team Cherry'],
                        'release_date' => ['date' => '24 Feb, 2017', 'coming_soon' => false],
                    ],
                ],
            ]),
        ]);

        $game = app(QuickAddService::class)->commit(
            $user,
            'https://store.steampowered.com/app/367520/Hollow_Knight/',
            QuickAddContentType::Games,
            []
        );

        expect($game)->toBeInstanceOf(LibraryGame::class);
        expect($game->title)->toBe('Hollow Knight');
        expect($game->platform)->toBe('pc');
        expect($game->developer)->toBe('Team Cherry');
        expect($game->publication_year)->toBe(2017);
    });

    it('commits a boardgamegeek url as a game', function () {
        $user = makeUser();

        Http::fake([
            'https://boardgamegeek.com/xmlapi2/thing*' => Http::response(
                '<?xml version="1.0" encoding="utf-8"?><items><item type="boardgame" id="174430">'
                .'<name type="primary" value="Gloomhaven"/><yearpublished value="2017"/>'
                .'<link type="boardgamedesigner" value="Isaac Childres"/>'
                .'<link type="boardgamepublisher" value="Cephalofair Games"/>'
                .'</item></items>',
                200,
                ['Content-Type' => 'application/xml']
            ),
        ]);

        $game = app(QuickAddService::class)->commit(
            $user,
            'https://boardgamegeek.com/boardgame/174430/gloomhaven',
            QuickAddContentType::Games,
            []
        );

        expect($game)->toBeInstanceOf(LibraryGame::class);
        expect($game->title)->toBe('Gloomhaven');
        expect($game->platform)->toBe('boardgame');
        expect($game->developer)->toBe('Isaac Childres');
        expect($game->publication_year)->toBe(2017);
    });

    it('commits an imdb series url as a series', function () {
        $user = makeUser();

        Http::preventStrayRequests();
        Http::fake([
            'https://api.themoviedb.org/3/find/tt0944947*' => Http::response([
                'movie_results' => [],
                'tv_results' => [['id' => 1399]],
            ]),
            'https://api.themoviedb.org/3/tv/1399*' => Http::response([
                'id' => 1399,
                'name' => 'Game of Thrones',
                'first_air_date' => '2011-04-17',
                'genres' => [],
            ]),
        ]);

        $movie = app(QuickAddService::class)->commit(
            $user,
            'https://www.imdb.com/title/tt0944947/',
            QuickAddContentType::Movies,
            []
        );

        expect($movie)->toBeInstanceOf(LibraryMovie::class);
        expect($movie->title)->toBe('Game of Thrones');
        expect($movie->category)->toBe('series');
        expect($movie->publication_year)->toBe(2011);
    });

    it('commits an imdb movie url as a movie', function () {
        $user = makeUser();

        Http::preventStrayRequests();
        Http::fake([
            'https://api.themoviedb.org/3/find/tt1160419*' => Http::response([
                'movie_results' => [['id' => 438631]],
                'tv_results' => [],
            ]),
            'https://api.themoviedb.org/3/movie/438631*' => Http::response([
                'id' => 438631,
                'title' => 'Dune',
                'release_date' => '2021-09-15',
                'genres' => [],
            ]),
        ]);

        $movie = app(QuickAddService::class)->commit(
            $user,
            'https://www.imdb.com/title/tt1160419/',
            QuickAddContentType::Movies,
            []
        );

        expect($movie)->toBeInstanceOf(LibraryMovie::class);
        expect($movie->title)->toBe('Dune');
        expect($movie->category)->toBe('movie');
        expect($movie->publication_year)->toBe(2021);
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
