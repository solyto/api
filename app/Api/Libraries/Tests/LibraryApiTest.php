<?php

use App\Api\Libraries\Enums\BookServiceEnum;
use App\Api\Libraries\Enums\MusicServiceEnum;
use App\Api\Libraries\Models\Author;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryBookGenre;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Models\LibraryMusicGenre;
use App\Api\Libraries\Services\External\DeezerService;
use App\Api\Libraries\Services\External\HardcoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

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
});

describe('Author management', function () {
    it('lists, stores, shows, updates and deletes authors', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/books/authors', ['name' => 'Frank Herbert'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Frank Herbert')
            ->assertJsonPath('data.is_favorite', false);

        $authorId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books/authors')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.books_count', 0)
            ->assertJsonPath('data.0.books', null);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books/authors/'.$authorId)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $authorId);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/books/authors/'.$authorId, [
                'bio' => 'American science fiction writer.',
                'is_favorite' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.bio', 'American science fiction writer.')
            ->assertJsonPath('data.is_favorite', true);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/books/authors/'.$authorId)
            ->assertStatus(200);

        expect(Author::count())->toBe(0);
    });

    it('scopes authors to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        Author::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books/authors')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('links a book to an author and syncs the free-text author field', function () {
        $user = makeUser();
        $author = Author::factory()->forUser($user)->withName('Ursula K. Le Guin')->create();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/books', [
                'title' => 'The Left Hand of Darkness',
                'author' => 'placeholder',
                'author_id' => $author->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.author', 'Ursula K. Le Guin')
            ->assertJsonPath('data.author_id', $author->id);

        $bookId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/libraries/books/authors/'.$author->id)
            ->assertStatus(200)
            ->assertJsonPath('data.books_count', 1)
            ->assertJsonPath('data.books.0.id', $bookId);
    });

    it('propagates an author rename to already-linked books', function () {
        $user = makeUser();
        $author = Author::factory()->forUser($user)->withName('Ursula K. Le Guin')->create();
        $book = LibraryBook::factory()->forUser($user)->create([
            'author' => 'Ursula K. Le Guin',
            'author_id' => $author->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/books/authors/'.$author->id, ['name' => 'Ursula LeGuin'])
            ->assertStatus(200);

        expect($book->fresh()->author)->toBe('Ursula LeGuin');
    });

    it('keeps a linked book synced to its author when only the free-text field is updated', function () {
        $user = makeUser();
        $author = Author::factory()->forUser($user)->withName('Ursula K. Le Guin')->create();
        $book = LibraryBook::factory()->forUser($user)->create([
            'author' => 'Ursula K. Le Guin',
            'author_id' => $author->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/books/'.$book->id, ['author' => 'Some Typo'])
            ->assertStatus(200)
            ->assertJsonPath('data.author', 'Ursula K. Le Guin')
            ->assertJsonPath('data.author_id', $author->id);
    });

    it('rejects author_id for an author belonging to another user', function () {
        $user = makeUser();
        $other = makeUser();
        $author = Author::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/books', [
                'title' => 'Some Book',
                'author' => 'placeholder',
                'author_id' => $author->id,
            ])
            ->assertStatus(422);
    });

    it('rejects a duplicate hardcover_id for the same user', function () {
        $user = makeUser();
        Author::factory()->forUser($user)->create(['hardcover_id' => 42]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/books/authors', ['name' => 'Someone Else', 'hardcover_id' => 42])
            ->assertStatus(422);
    });

    it('allows two different users to link the same hardcover_id', function () {
        $user = makeUser();
        $other = makeUser();
        Author::factory()->forUser($other)->create(['hardcover_id' => 42]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/books/authors', ['name' => 'Someone Else', 'hardcover_id' => 42])
            ->assertStatus(201);
    });

    it('accepts an explicit null is_favorite on update', function () {
        $user = makeUser();
        $author = Author::factory()->forUser($user)->create(['is_favorite' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/libraries/books/authors/'.$author->id, ['is_favorite' => null])
            ->assertStatus(200);
    });

    it('unlinks books but keeps the free-text author when an author is deleted', function () {
        $user = makeUser();
        $author = Author::factory()->forUser($user)->create();
        $book = LibraryBook::factory()->forUser($user)->create([
            'author' => $author->name,
            'author_id' => $author->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/libraries/books/authors/'.$author->id)
            ->assertStatus(200);

        $book->refresh();

        expect($book->author_id)->toBeNull();
        expect($book->author)->toBe($author->name);
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
                        'contributions' => [['author' => ['id' => 1, 'name' => 'Frank Herbert', 'image' => ['url' => 'https://cover.example/herbert.jpg']]]],
                    ]],
                ],
            ]),
        ]);

        $dto = app(HardcoverService::class)->importFromUrl('https://hardcover.app/books/dune');

        expect($dto)->not->toBeNull();
        expect($dto->getTitle())->toBe('Dune');
        expect($dto->getAuthor())->toBe('Frank Herbert');
        expect($dto->getAuthorId())->toBe(1);
        expect($dto->getAuthorPhoto())->toBe('https://cover.example/herbert.jpg');
        expect($dto->getProvider())->toBe(BookServiceEnum::HARDCOVER->value);
        expect($dto->getPageCount())->toBe(412);
        expect($dto->getReleaseDate()?->format('Y-m-d'))->toBe('1965-08-01');
    });

    it('fetches an author by hardcover id', function () {
        Http::fake([
            'https://api.hardcover.app/v1/graphql' => Http::response([
                'data' => [
                    'authors' => [[
                        'id' => 1,
                        'name' => 'Frank Herbert',
                        'bio' => 'American science fiction writer.',
                        'image' => ['url' => 'https://cover.example/herbert.jpg'],
                    ]],
                ],
            ]),
        ]);

        $author = app(HardcoverService::class)->getAuthor(1);

        expect($author['name'])->toBe('Frank Herbert');
        expect($author['bio'])->toBe('American science fiction writer.');
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

describe('Library book import', function () {
    it('auto-creates and links an author when importing a book from hardcover', function () {
        Storage::fake('user_data');
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
                        'contributions' => [['author' => ['id' => 1, 'name' => 'Frank Herbert', 'image' => ['url' => 'https://cover.example/herbert.jpg']]]],
                    ]],
                ],
            ]),
            'https://cover.example/*' => Http::response('binary-image-data', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/books/import/hardcover', ['url' => 'https://hardcover.app/books/dune'])
            ->assertStatus(200)
            ->assertJsonPath('data.author', 'Frank Herbert');

        $authorId = $response->json('data.author_id');
        expect($authorId)->not->toBeNull();

        $author = Author::find($authorId);
        expect($author->name)->toBe('Frank Herbert');
        expect($author->hardcover_id)->toBe(1);
        expect($author->photo)->not->toBeNull();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/libraries/books/import/hardcover', ['url' => 'https://hardcover.app/books/dune'])
            ->assertStatus(200)
            ->assertJsonPath('data.author_id', $authorId);

        expect(Author::count())->toBe(1);
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
});
