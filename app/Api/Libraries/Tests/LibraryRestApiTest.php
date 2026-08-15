<?php

use App\Api\Libraries\Models\LibraryGame;
use App\Api\Libraries\Models\LibraryLink;
use App\Api\Libraries\Models\LibraryLinkCategory;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryPlant;
use App\Api\Libraries\Models\LibraryQuote;
use App\Api\Libraries\Models\LibraryRecipe;
use App\Api\Libraries\Services\External\BggService;
use App\Api\Libraries\Services\External\ChefkochService;
use App\Api\Libraries\Services\External\ImdbService;
use App\Api\Libraries\Services\External\SteamService;
use App\Api\Libraries\Services\External\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

describe('Library games', function () {
    it('lists, stores, updates and deletes games', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/games', ['title' => 'Hollow Knight', 'platform' => 'pc'])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Hollow Knight');

        $gameId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/games')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/games/'.$gameId, ['rating' => 5])
            ->assertStatus(200)
            ->assertJsonPath('data.rating', 5);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/games/'.$gameId)
            ->assertStatus(200);

        expect(LibraryGame::count())->toBe(0);
    });

    it('searches games via steam', function () {
        Http::fake([
            'https://store.steampowered.com/api/storesearch*' => Http::response([
                'items' => [
                    ['id' => 367520, 'name' => 'Hollow Knight', 'tiny_image' => 'https://cdn.example/hk.jpg'],
                ],
            ]),
        ]);

        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/games/search/steam/Hollow%20Knight')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Hollow Knight');
    });
});

describe('Library movies', function () {
    it('lists, stores, updates and deletes movies', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/movies', ['title' => 'Dune Part Two', 'category' => 'movie'])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Dune Part Two');

        $movieId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/movies')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/movies/'.$movieId)
            ->assertStatus(200);

        expect(LibraryMovie::count())->toBe(0);
    });
});

describe('Library links', function () {
    it('lists, stores, updates, deletes and lists newest links', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/links', ['url' => 'https://example.com/article'])
            ->assertStatus(201);

        $linkId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/links/newest')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/links/'.$linkId, ['is_favorite' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.is_favorite', true);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/links/'.$linkId)
            ->assertStatus(200);

        expect(LibraryLink::count())->toBe(0);
    });

    it('manages link categories', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/links/categories', ['title' => 'Reading'])
            ->assertStatus(201);

        $categoryId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/links/categories')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/links/categories/'.$categoryId)
            ->assertStatus(200);

        expect(LibraryLinkCategory::count())->toBe(0);
    });
});

describe('Library quotes', function () {
    it('lists, stores and deletes quotes', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/quotes', [
                'quote' => 'Be the change',
                'author' => 'Gandhi',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.quote', 'Be the change');

        $quoteId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/quotes')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/quotes/random')
            ->assertStatus(200)
            ->assertJsonPath('data.quote', 'Be the change');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/quotes/'.$quoteId)
            ->assertStatus(200);

        expect(LibraryQuote::count())->toBe(0);
    });
});

describe('Library recipes', function () {
    it('lists, stores, updates and deletes recipes', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/recipes', ['title' => 'Pancakes', 'time_to_make' => 20])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Pancakes');

        $recipeId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/recipes')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/recipes/'.$recipeId)
            ->assertStatus(200);

        expect(LibraryRecipe::count())->toBe(0);
    });

});

describe('Library plants', function () {
    it('lists, stores and deletes plants', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/plants', [
                'name' => 'Monstera',
                'location' => 'indoor',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Monstera');

        $plantId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/plants')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/plants/'.$plantId)
            ->assertStatus(200);

        expect(LibraryPlant::count())->toBe(0);
    });

    it('uploads a plant cover', function () {
        Queue::fake();
        Storage::fake('user_data');
        $user = makeUser();
        $plant = LibraryPlant::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/libraries/plants/'.$plant->id.'/cover', [
                'file' => UploadedFile::fake()->image('plant.png', 100, 100),
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($plant->fresh()->cover_path)->not->toBeNull();
    });
});

describe('Cover serving', function () {
    it('serves a stored cover image', function () {
        Storage::fake('user_data');
        $user = makeUser();

        Storage::disk('user_data')->put($user->id.'/books/test.jpg', 'fake-image-bytes');

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/libraries/covers/books/test.jpg')
            ->assertStatus(200);
    });

    it('returns 404 for missing covers', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/libraries/covers/books/missing.jpg')
            ->assertStatus(404);
    });
});

describe('External services', function () {
    it('steam imports a game from a store url', function () {
        Http::fake([
            'https://store.steampowered.com/api/appdetails?appids=*' => Http::response([
                '367520' => [
                    'success' => true,
                    'data' => [
                        'type' => 'game',
                        'name' => 'Hollow Knight',
                        'header_image' => 'https://cdn.example/hk.jpg',
                        'short_description' => 'A metroidvania',
                        'developers' => ['Team Cherry'],
                        'publishers' => ['Team Cherry'],
                        'genres' => [['description' => 'Metroidvania']],
                        'release_date' => ['date' => '24 Feb, 2017', 'coming_soon' => false],
                    ],
                ],
            ]),
        ]);

        $dto = app(SteamService::class)->importFromUrl('https://store.steampowered.com/app/367520/Hollow_Knight/');

        expect($dto)->not->toBeNull();
        expect($dto->getTitle())->toBe('Hollow Knight');
        expect($dto->getDeveloper())->toBe('Team Cherry');
    });

    it('imdb imports a movie from a title url', function () {
        Http::fake([
            'https://api.imdbapi.dev/titles/tt1160419' => Http::response([
                'id' => 'tt1160419',
                'primaryTitle' => 'Dune',
                'type' => 'movie',
                'plot' => 'A mythic journey.',
                'primaryImage' => ['url' => 'https://img.example/dune.jpg'],
                'startYear' => 2021,
                'runtimeSeconds' => 9300,
                'genres' => ['Sci-Fi'],
            ]),
        ]);

        $dto = app(ImdbService::class)->importFromUrl('https://www.imdb.com/title/tt1160419/');

        expect($dto)->not->toBeNull();
        expect($dto->getTitle())->toBe('Dune');
        expect($dto->getReleaseYear())->toBe(2021);
        expect($dto->getRuntime())->toBe(155);
    });

    it('tmdb searches movies', function () {
        Http::fake([
            'https://api.themoviedb.org/3/search/movie*' => Http::response([
                'results' => [
                    ['id' => 1, 'title' => 'Dune', 'release_date' => '2021-10-22', 'poster_path' => '/x.jpg'],
                ],
            ]),
        ]);

        $results = app(TmdbService::class)->searchMovie('Dune');

        expect($results)->toHaveCount(1);
    });

    it('bgg searches boardgames', function () {
        Http::fake([
            'https://boardgamegeek.com/xmlapi2/search*' => Http::response(
                '<?xml version="1.0" encoding="utf-8"?><items><item type="boardgame" id="174430"><name value="Gloomhaven" type="primary"/></item></items>',
                200,
                ['Content-Type' => 'application/xml']
            ),
        ]);

        $results = app(BggService::class)->searchGames('Gloomhaven');

        expect($results)->toHaveCount(1);
    });

    it('chefkoch searches recipes through the service', function () {
        Http::fake([
            'https://api.chefkoch.de/v2/api-gateway/recipes/v1/search*' => Http::response([
                'results' => [
                    ['id' => 2, 'title' => 'Soup'],
                ],
            ]),
        ]);

        $results = app(ChefkochService::class)->searchRecipes('Soup');

        expect($results)->toHaveCount(1);
        expect($results[0]['title'])->toBe('Soup');
    });
});
