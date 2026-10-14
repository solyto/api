<?php

use App\Api\Libraries\Enums\BookServiceEnum;
use App\Api\Libraries\Enums\MusicServiceEnum;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryBookGenre;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Models\LibraryMusicGenre;
use App\Api\Libraries\Services\External\DeezerService;
use App\Api\Libraries\Services\External\HardcoverService;
use App\Api\Tags\Models\Tag;
use App\Plugins\Models\UserPlugin;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

describe('Library book CRUD', function () {
    it('lists, stores, shows, updates and deletes books', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/books', [
                'title' => 'Dune',
                'author' => 'Frank Herbert',
                'pages' => 412,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Dune');

        $bookId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books/'.$bookId)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $bookId);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/books/'.$bookId, ['rating' => 5])
            ->assertStatus(200)
            ->assertJsonPath('data.rating', 5);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/books/'.$bookId)
            ->assertStatus(200);

        expect(LibraryBook::count())->toBe(0);
    });

    it('scopes books to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        LibraryBook::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('manages book genres', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/books/genres', ['title' => 'Sci-Fi'])
            ->assertStatus(201);

        $genreId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books/genres')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/books/genres/'.$genreId)
            ->assertStatus(200);

        expect(LibraryBookGenre::count())->toBe(0);
    });

    it('rejects another user\'s genres and tags on store and update', function () {
        $user = makeUser();
        $other = makeUser();
        $book = LibraryBook::factory()->forUser($user)->create();
        $foreignGenre = LibraryBookGenre::factory()->forUser($other)->create();
        $foreignTag = Tag::factory()->forUser($other)->create();
        $payload = ['title' => 'Dune', 'author' => 'Frank Herbert', 'pages' => 412];

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/books', $payload + ['genres' => [$foreignGenre->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('genres.0');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/books', $payload + ['tags' => [$foreignTag->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tags.0');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/books/'.$book->id, ['genres' => [$foreignGenre->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('genres.0');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/books/'.$book->id, ['tags' => [$foreignTag->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tags.0');

        expect(LibraryBook::count())->toBe(1);
        expect($book->fresh()->genres)->toHaveCount(0);
        expect($book->fresh()->tags)->toHaveCount(0);
    });
});

describe('Library music CRUD', function () {
    it('lists, stores, shows, updates and deletes music', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/music', [
                'title' => 'OK Computer',
                'artist' => 'Radiohead',
                'type' => 'album',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'OK Computer');

        $musicId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/music')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/music/'.$musicId, ['rating' => 4])
            ->assertStatus(200)
            ->assertJsonPath('data.rating', 4);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/music/'.$musicId)
            ->assertStatus(200);

        expect(LibraryMusic::count())->toBe(0);
    });

    it('manages music genres', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/music/genres', ['title' => 'Rock'])
            ->assertStatus(201);

        $genreId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/music/genres/'.$genreId)
            ->assertStatus(200);

        expect(LibraryMusicGenre::count())->toBe(0);
    });

    it('rejects another user\'s genres on store and update', function () {
        $user = makeUser();
        $other = makeUser();
        $music = LibraryMusic::factory()->forUser($user)->create();
        $foreignGenre = LibraryMusicGenre::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/music', [
                'title' => 'OK Computer',
                'artist' => 'Radiohead',
                'type' => 'album',
                'genres' => [$foreignGenre->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('genres.0');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/music/'.$music->id, ['genres' => [$foreignGenre->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('genres.0');

        expect(LibraryMusic::count())->toBe(1);
        expect($music->fresh()->genres)->toHaveCount(0);
    });
});

describe('HardcoverService', function () {
    it('imports a book from a hardcover url', function () {
        Http::fake([
            'https://api.hardcover.app/v1/graphql' => Http::response([
                'data' => [
                    'books' => [[
                        'id' => 123,
                        'slug' => 'dune',
                        'title' => 'Dune',
                        'release_date' => '1965-08-01',
                        'description' => 'A classic',
                        'pages' => 412,
                        'image' => ['url' => 'https://cover.example/dune.jpg'],
                        'default_cover_edition' => null,
                        'contributions' => [['author' => ['id' => 1, 'name' => 'Frank Herbert']]],
                    ]],
                ],
            ]),
        ]);

        $dto = app(HardcoverService::class)->importFromUrl('https://hardcover.app/books/dune');

        expect($dto)->not->toBeNull();
        expect($dto->getTitle())->toBe('Dune');
        expect($dto->getAuthor())->toBe('Frank Herbert');
        expect($dto->getProvider())->toBe(BookServiceEnum::HARDCOVER->value);
        expect($dto->getPageCount())->toBe(412);
        expect($dto->getReleaseDate()?->format('Y-m-d'))->toBe('1965-08-01');
    });

    it('searches books', function () {
        Http::fake([
            'https://api.hardcover.app/v1/graphql' => Http::response([
                'data' => [
                    'search' => [
                        'results' => [
                            'hits' => [[
                                'document' => [
                                    'id' => 42,
                                    'title' => 'Foundation',
                                    'author_names' => ['Isaac Asimov'],
                                    'image' => ['url' => 'https://cover.example/f.jpg'],
                                    'release_year' => 1951,
                                    'slug' => 'foundation',
                                ],
                            ]],
                        ],
                    ],
                ],
            ]),
        ]);

        $results = app(HardcoverService::class)->searchBooks('Foundation');

        expect($results)->toHaveCount(1);
        expect($results[0]->getTitle())->toBe('Foundation');
        expect($results[0]->getAuthor())->toBe('Isaac Asimov');
    });

    it('returns null when the api call fails', function () {
        Http::fake([
            'https://api.hardcover.app/v1/graphql' => Http::response([], 500),
        ]);

        expect(app(HardcoverService::class)->searchBooks('x'))->toBeNull();
    });
});

describe('DeezerService', function () {
    it('searches albums', function () {
        Http::fake([
            'https://api.deezer.com/search/album?q=*' => Http::response([
                'data' => [[
                    'id' => 5,
                    'title' => 'OK Computer',
                    'artist' => ['name' => 'Radiohead'],
                    'cover_big' => 'https://cover.example/ok.jpg',
                    'release_date' => '1997-05-21',
                    'link' => 'https://www.deezer.com/album/5',
                ]],
            ]),
        ]);

        $results = app(DeezerService::class)->searchAlbum('OK Computer');

        expect($results)->toHaveCount(1);
        expect($results[0]->getTitle())->toBe('OK Computer');
        expect($results[0]->getArtist())->toBe('Radiohead');
        expect($results[0]->getReleaseYear())->toBe(1997);
        expect($results[0]->getProvider())->toBe(MusicServiceEnum::DEEZER->value);
    });

    it('imports an album from a deezer url', function () {
        Http::fake([
            'https://api.deezer.com/album/5' => Http::response([
                'id' => 5,
                'title' => 'OK Computer',
                'artist' => ['id' => 6, 'name' => 'Radiohead'],
                'link' => 'https://www.deezer.com/album/5',
                'cover_big' => 'https://cover.example/ok.jpg',
                'release_date' => '1997-05-21',
                'record_type' => 'album',
                'genres' => ['data' => [['name' => 'Rock']]],
            ]),
        ]);

        $dto = app(DeezerService::class)->importFromUrl('https://www.deezer.com/album/5');

        expect($dto)->not->toBeNull();
        expect($dto->getTitle())->toBe('OK Computer');
        expect($dto->getArtist())->toBe('Radiohead');
        expect($dto->getGenres())->toBe(['Rock']);
    });

    it('returns null on failure', function () {
        Http::fake([
            'https://api.deezer.com/search/album?q=*' => Http::response([], 500),
        ]);

        expect(app(DeezerService::class)->searchAlbum('x'))->toBeNull();
    });
});

describe('Library search endpoints', function () {
    it('searches books via hardcover', function () {
        Http::fake([
            'https://api.hardcover.app/v1/graphql' => Http::response([
                'data' => [
                    'search' => [
                        'results' => [
                            'hits' => [[
                                'document' => [
                                    'id' => 1,
                                    'title' => 'Dune',
                                    'author_names' => ['Frank Herbert'],
                                    'image' => ['url' => null],
                                    'release_year' => 1965,
                                    'slug' => 'dune',
                                ],
                            ]],
                        ],
                    ],
                ],
            ]),
        ]);

        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books/search/hardcover/Dune')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Dune');
    });

    it('searches music via deezer', function () {
        Http::fake([
            'https://api.deezer.com/search/album?q=*' => Http::response([
                'data' => [[
                    'id' => 9,
                    'title' => 'Kid A',
                    'artist' => ['name' => 'Radiohead'],
                    'cover_big' => null,
                    'release_date' => '2000-10-02',
                    'link' => 'https://www.deezer.com/album/9',
                ]],
            ]),
        ]);

        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/music/search/deezer/Kid%20A')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Kid A');
    });

    it('searches music via spotify', function () {
        config([
            'services.spotify.client_id' => 'test-client-id',
            'services.spotify.client_secret' => 'test-client-secret',
        ]);

        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response(['access_token' => 'tok123', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            'https://api.spotify.com/v1/search*' => Http::response([
                'albums' => ['items' => [[
                    'id' => '4aawyAB9vmqN3uQ7FjRGTy',
                    'name' => 'Kid A',
                    'artists' => [['name' => 'Radiohead']],
                    'images' => [['url' => null]],
                    'release_date' => '2000-10-02',
                    'release_date_precision' => 'day',
                ]]],
            ]),
        ]);

        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/music/search/spotify/Kid%20A')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Kid A')
            ->assertJsonPath('data.0.provider', 'spotify')
            ->assertJsonPath('data.0.id', '4aawyAB9vmqN3uQ7FjRGTy');
    });

    it('imports music via spotify', function () {
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
                'images' => [['url' => 'https://cover.example/ok.jpg']],
                'release_date' => '1997-05-21',
                'release_date_precision' => 'day',
                'album_type' => 'album',
                'genres' => ['alternative rock'],
            ]),
        ]);

        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/music/import/spotify', [
                'url' => 'https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'OK Computer')
            ->assertJsonPath('data.provider', 'spotify')
            ->assertJsonPath('data.id', '4aawyAB9vmqN3uQ7FjRGTy')
            ->assertJsonPath('data.artist_id', '5K4W6rqBFWDnAN6FQUkS6x');
    });

    it('lists configured music integrations', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/music/integrations')
            ->assertStatus(200)
            ->assertJsonPath('data.integrations', ['deezer']);
    });

    it('includes discogs and spotify when their credentials are configured', function () {
        config([
            'services.discogs.access_token' => 'test-token',
            'services.spotify.client_id' => 'test-client-id',
            'services.spotify.client_secret' => 'test-client-secret',
        ]);

        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/music/integrations')
            ->assertStatus(200)
            ->assertJsonPath('data.integrations', ['deezer', 'discogs', 'spotify']);
    });
});

describe('Library recommend endpoints', function () {
    it('recommends a random book and returns 404 without books', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books/recommend/random')
            ->assertStatus(404);

        $book = LibraryBook::factory()->forUser($user)->create(['title' => 'Dune']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books/recommend/random')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $book->id)
            ->assertJsonPath('data.title', 'Dune');
    });

    it('recommends a random music item and returns 404 without music', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/music/recommend/random')
            ->assertStatus(404);

        $music = LibraryMusic::factory()->forUser($user)->create(['title' => 'Kind of Blue']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/music/recommend/random')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $music->id)
            ->assertJsonPath('data.title', 'Kind of Blue');
    });
});

describe('Library NEW recommendations without a usable AI plugin', function () {
    it('returns the same 404 as without favorites', function (string $type, Closure $favorite, Closure $setup) {
        $user = makeUser();

        $withoutFavorites = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/libraries/{$type}/recommend/new");
        expectApiError($withoutFavorites, 404);

        $favorite($user);
        $setup($user);

        $withoutAi = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/libraries/{$type}/recommend/new");
        expectApiError($withoutAi, 404);

        expect(Arr::except($withoutAi->json(), 'timestamp'))->toBe(Arr::except($withoutFavorites->json(), 'timestamp'));
    })->with([
        'books' => ['books', fn () => fn ($user) => LibraryBook::factory()->forUser($user)->create(['rating' => 5])],
        'music' => ['music', fn () => fn ($user) => LibraryMusic::factory()->forUser($user)->create(['rating' => 5])],
    ])->with([
        'no plugin' => [fn () => fn ($user) => null],
        'plugin disabled' => [fn () => fn ($user) => UserPlugin::factory()->forUser($user)->create(['enabled' => false])],
    ]);
});

describe('Library books and music tenant isolation', function () {
    it('forbids access to another user\'s book', function () {
        $user = makeUser();
        $other = makeUser();
        $book = LibraryBook::factory()->forUser($other)->create(['rating' => 2]);

        expectApiError($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books/'.$book->id), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/books/'.$book->id, ['rating' => 5]), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/books/'.$book->id), 403);

        expect($book->fresh()?->rating)->toBe(2);
    });

    it('hides another user\'s music and forbids access to it', function () {
        $user = makeUser();
        $other = makeUser();
        $music = LibraryMusic::factory()->forUser($other)->create(['rating' => 2]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/music')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        expectApiError($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/music/'.$music->id), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/music/'.$music->id, ['rating' => 5]), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/music/'.$music->id), 403);

        expect($music->fresh()?->rating)->toBe(2);
    });

    it('hides another user\'s book and music genres and forbids access to them', function () {
        $user = makeUser();
        $other = makeUser();
        $genres = [
            'books' => LibraryBookGenre::factory()->forUser($other)->create(['title' => 'Theirs']),
            'music' => LibraryMusicGenre::factory()->forUser($other)->create(['title' => 'Theirs']),
        ];

        foreach ($genres as $type => $genre) {
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/libraries/'.$type.'/genres')
                ->assertStatus(200)
                ->assertJsonCount(0, 'data');

            expectApiError($this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/libraries/'.$type.'/genres/'.$genre->id), 403);

            expectApiError($this->actingAs($user, 'sanctum')
                ->putJson('/api/v1/libraries/'.$type.'/genres/'.$genre->id, ['title' => 'Hijacked']), 403);

            expectApiError($this->actingAs($user, 'sanctum')
                ->deleteJson('/api/v1/libraries/'.$type.'/genres/'.$genre->id), 403);

            expect($genre->fresh()?->title)->toBe('Theirs');
        }
    });
});
