<?php

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Services\LibraryRecommender;
use App\Api\Libraries\Services\LibraryReleases;
use App\Plugins\Ai\Services\AiService;

describe('LibraryRecommender', function () {
    it('throws for types without a recommender', function () {
        $this->mock(AiService::class);

        expect(fn () => app(LibraryRecommender::class)->favorite(LibraryTypeEnum::MOVIE, makeUser()))
            ->toThrow(\Exception::class);
    });

    it('recommends a favorite book', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 5, 'author' => 'Frank Herbert', 'title' => 'Dune']);

        $this->mock(AiService::class);

        $favorite = app(LibraryRecommender::class)->favorite(LibraryTypeEnum::BOOK, $user);

        expect($favorite['title'])->toBe('Dune');
        expect($favorite['creator'])->toBe('Frank Herbert');
    });

    it('recommends an unrated book', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => null, 'title' => 'Unrated']);

        $this->mock(AiService::class);

        expect(app(LibraryRecommender::class)->unrated(LibraryTypeEnum::BOOK, $user)['title'])->toBe('Unrated');
    });

    it('returns null for favorite when nothing is rated 4 or higher', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 3]);

        $this->mock(AiService::class);

        expect(app(LibraryRecommender::class)->favorite(LibraryTypeEnum::BOOK, $user))->toBeNull();
    });

    it('returns null for unrated when everything is rated', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 3]);

        $this->mock(AiService::class);

        expect(app(LibraryRecommender::class)->unrated(LibraryTypeEnum::BOOK, $user))->toBeNull();
    });

    it('returns null for random when the library is empty', function () {
        $user = makeUser();

        $this->mock(AiService::class);

        expect(app(LibraryRecommender::class)->random(LibraryTypeEnum::BOOK, $user))->toBeNull();
    });

    it('new() calls the AI service with the user and their favorites', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 5, 'author' => 'Frank Herbert', 'title' => 'Dune']);

        $ai = $this->mock(AiService::class);
        $ai->shouldReceive('respondStructured')
            ->once()
            ->withArgs(fn ($passedUser, $prompt, $input) => $passedUser->is($user) && str_contains($input, 'Dune by Frank Herbert'))
            ->andReturn([
            'recommendations' => [['title' => 'Children of Dune', 'creator' => 'Frank Herbert', 'genre' => 'Sci-Fi']],
        ]);

        $result = app(LibraryRecommender::class)->new(LibraryTypeEnum::BOOK, $user);

        expect($result['recommendations'][0]['title'])->toBe('Children of Dune');
    });

    it('new() returns null when the AI service is unavailable for the user', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 5]);

        $this->mock(AiService::class)->shouldReceive('respondStructured')->once()->andReturn(null);

        expect(app(LibraryRecommender::class)->new(LibraryTypeEnum::BOOK, $user))->toBeNull();
    });

    it('new() returns null without favorites', function () {
        $user = makeUser();

        $this->mock(AiService::class)->shouldNotReceive('respondStructured');

        expect(app(LibraryRecommender::class)->new(LibraryTypeEnum::BOOK, $user))->toBeNull();
    });
});

describe('LibraryReleases', function () {
    it('collects music releases from deezer for favorite artists', function () {
        $user = makeUser();
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Radiohead']);

        $deezer = $this->mock(\App\Api\Libraries\Services\External\DeezerService::class);
        $deezer->shouldReceive('searchArtists')->with('Radiohead')->andReturn([['id' => 6]]);
        $deezer->shouldReceive('getNewReleases')->with(6)->andReturn([[
            'id' => 1,
            'title' => 'New Album',
            'link' => 'https://deezer.com/album/1',
            'cover_big' => 'https://cover.example/1.jpg',
            'release_date' => now()->toDateString(),
        ]]);

        $service = app(LibraryReleases::class);

        $releases = $service->getMusicReleases($user);

        expect($releases)->toHaveCount(1);
        expect($releases[0]->getTitle())->toBe('New Album');
        expect($releases[0]->getArtist())->toBe('Radiohead');
    });

    it('skips artists with no new releases', function () {
        $user = makeUser();
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Radiohead']);

        $deezer = $this->mock(\App\Api\Libraries\Services\External\DeezerService::class);
        $deezer->shouldReceive('searchArtists')->with('Radiohead')->andReturn([['id' => 6]]);
        $deezer->shouldReceive('getNewReleases')->with(6)->andReturn(null);

        $service = app(LibraryReleases::class);

        expect($service->getMusicReleases($user))->toBe([]);
    });

    it('collects spotify music releases for favorite artists', function () {
        $user = makeUser();
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Radiohead']);

        $spotify = $this->mock(\App\Api\Libraries\Services\External\SpotifyService::class);
        $spotify->shouldReceive('searchArtists')->with('Radiohead')->andReturn([['id' => '5K4W6rqBFWDnAN6FQUkS6x']]);
        $spotify->shouldReceive('getNewReleases')->with('5K4W6rqBFWDnAN6FQUkS6x')->andReturn([[
            'id' => '4aawyAB9vmqN3uQ7FjRGTy',
            'name' => 'Fresh Album',
            'images' => [['url' => 'https://cover.example/fresh.jpg']],
            'release_date' => now()->toDateString(),
            'release_date_precision' => 'day',
        ]]);

        $service = app(LibraryReleases::class);

        $releases = $service->getSpotifyMusicReleases($user);

        expect($releases)->toHaveCount(1);
        expect($releases[0]->getTitle())->toBe('Fresh Album');
        expect($releases[0]->getArtist())->toBe('Radiohead');
        expect($releases[0]->getProvider())->toBe(\App\Api\Libraries\Enums\MusicServiceEnum::SPOTIFY->value);
        expect($releases[0]->getId())->toBe('4aawyAB9vmqN3uQ7FjRGTy');
        expect($releases[0]->getArtistId())->toBe('5K4W6rqBFWDnAN6FQUkS6x');
    });

    it('skips artists with no spotify releases', function () {
        $user = makeUser();
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Radiohead']);

        $spotify = $this->mock(\App\Api\Libraries\Services\External\SpotifyService::class);
        $spotify->shouldReceive('searchArtists')->with('Radiohead')->andReturn([['id' => '5K4W6rqBFWDnAN6FQUkS6x']]);
        $spotify->shouldReceive('getNewReleases')->with('5K4W6rqBFWDnAN6FQUkS6x')->andReturn(null);

        $service = app(LibraryReleases::class);

        expect($service->getSpotifyMusicReleases($user))->toBe([]);
    });

    it('collects book releases from hardcover for favorite authors', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 5, 'author' => 'Frank Herbert']);

        $hardcover = $this->mock(\App\Api\Libraries\Services\External\HardcoverService::class);
        $hardcover->shouldReceive('getNewReleases')->with('Frank Herbert')->andReturn([[
            'id' => 1,
            'slug' => 'children-of-dune',
            'title' => 'Children of Dune',
            'release_date' => now()->toDateString(),
            'description' => 'Third book',
            'pages' => 444,
            'image' => ['url' => 'https://cover.example/cod.jpg'],
            'contributions' => [['author' => ['id' => 1, 'name' => 'Frank Herbert']]],
        ]]);

        $service = app(LibraryReleases::class);

        $releases = $service->getBookReleases($user);

        expect($releases)->toHaveCount(1);
        expect($releases[0]->getTitle())->toBe('Children of Dune');
        expect($releases[0]->getAuthor())->toBe('Frank Herbert');
    });
});
