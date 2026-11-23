<?php

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Services\LibraryBookService;
use App\Api\Libraries\Services\LibraryRecommender;
use App\Api\Libraries\Services\LibraryReleases;
use App\Plugins\Ai\Services\AiService;
use Illuminate\Support\Facades\Cache;

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

    it('new() keeps only recommendations with a title and a creator', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 5]);
        $good = ['title' => 'Children of Dune', 'creator' => 'Frank Herbert', 'genre' => 'Sci-Fi'];

        $this->mock(AiService::class)->shouldReceive('respondStructured')->once()->andReturn([
            'recommendations' => [$good, ['title' => 'No creator'], 'just a string', ['title' => 42, 'creator' => 'Someone']],
        ]);

        expect(app(LibraryRecommender::class)->new(LibraryTypeEnum::BOOK, $user))->toBe(['recommendations' => [$good]]);
    });

    it('new() gives no recommendations when the answer has no list of them', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 5]);

        $this->mock(AiService::class)->shouldReceive('respondStructured')->once()->andReturn(['recommendations' => 'Dune']);

        expect(app(LibraryRecommender::class)->new(LibraryTypeEnum::BOOK, $user))->toBe(['recommendations' => []]);
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
        $deezer->shouldReceive('searchManyArtists')->with(['Radiohead'])->andReturn([[['id' => 6]]]);
        $deezer->shouldReceive('getNewReleasesForArtists')->with([6])->andReturn([6 => [[
            'id' => 1,
            'title' => 'New Album',
            'link' => 'https://deezer.com/album/1',
            'cover_big' => 'https://cover.example/1.jpg',
            'release_date' => now()->toDateString(),
        ]]]);

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
        $deezer->shouldReceive('searchManyArtists')->with(['Radiohead'])->andReturn([[['id' => 6]]]);
        $deezer->shouldReceive('getNewReleasesForArtists')->with([6])->andReturn([6 => null]);

        $service = app(LibraryReleases::class);

        expect($service->getMusicReleases($user))->toBe([]);
    });

    it('gives every artist its releases when two artists resolve to the same deezer id', function () {
        $user = makeUser();
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Radiohead, Thom Yorke']);
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Thom Yorke']);
        LibraryMusic::factory()->forUser($user)->create(['rating' => 4, 'artist' => 'Radiohead']);
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Nobody']);
        LibraryMusic::factory()->forUser($user)->create(['rating' => 5, 'artist' => 'Portishead']);

        $album = fn (int $id, string $releaseDate) => [
            'id' => $id,
            'title' => 'Album '.$id,
            'link' => 'https://deezer.com/album/'.$id,
            'cover_big' => null,
            'release_date' => $releaseDate,
        ];

        $deezer = $this->mock(\App\Api\Libraries\Services\External\DeezerService::class);
        $deezer->shouldReceive('searchManyArtists')
            ->once()
            ->with(['Radiohead', 'Thom Yorke', 'Nobody', 'Portishead'])
            ->andReturn([[['id' => 6]], [['id' => 6]], [], [['id' => 7]]]);
        $deezer->shouldReceive('getNewReleasesForArtists')
            ->once()
            ->with([0 => 6, 1 => 6, 3 => 7])
            ->andReturn([6 => [$album(1, now()->subDays(3)->toDateString())], 7 => [$album(2, now()->toDateString())]]);

        $releases = app(LibraryReleases::class)->getMusicReleases($user);

        expect(array_map(fn ($release) => [$release->getId(), $release->getArtist(), $release->getArtistId()], $releases))
            ->toBe([[2, 'Portishead', 7], [1, 'Radiohead', 6], [1, 'Thom Yorke', 6]]);
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
        $hardcover->shouldReceive('getNewReleasesForAuthors')->with(['Frank Herbert'])->andReturn([[[
            'id' => 1,
            'slug' => 'children-of-dune',
            'title' => 'Children of Dune',
            'release_date' => now()->toDateString(),
            'description' => 'Third book',
            'pages' => 444,
            'image' => ['url' => 'https://cover.example/cod.jpg'],
            'contributions' => [['author' => ['id' => 1, 'name' => 'Frank Herbert']]],
        ]]]);

        $service = app(LibraryReleases::class);

        $releases = $service->getBookReleases($user);

        expect($releases)->toHaveCount(1);
        expect($releases[0]->getTitle())->toBe('Children of Dune');
        expect($releases[0]->getAuthor())->toBe('Frank Herbert');
    });

    it('looks up each distinct first author once, in one batch, and sorts by release date', function () {
        $user = makeUser();
        LibraryBook::factory()->forUser($user)->create(['rating' => 5, 'author' => 'Frank Herbert, Brian Herbert']);
        LibraryBook::factory()->forUser($user)->create(['rating' => 4, 'author' => 'Ursula K. Le Guin']);
        LibraryBook::factory()->forUser($user)->create(['rating' => 5, 'author' => 'Frank Herbert']);
        LibraryBook::factory()->forUser($user)->create(['rating' => 5, 'author' => 'Nobody']);
        LibraryBook::factory()->forUser($user)->create(['rating' => 3, 'author' => 'Not A Favorite']);

        $book = fn (int $id, string $releaseDate) => [
            'id' => $id,
            'slug' => 'book-'.$id,
            'title' => 'Book '.$id,
            'release_date' => $releaseDate,
            'description' => null,
            'pages' => 100,
            'image' => null,
            'contributions' => [],
        ];

        $hardcover = $this->mock(\App\Api\Libraries\Services\External\HardcoverService::class);
        $hardcover->shouldReceive('getNewReleasesForAuthors')
            ->once()
            ->with(['Frank Herbert', 'Ursula K. Le Guin', 'Nobody'])
            ->andReturn([
                [$book(1, now()->subMonths(2)->toDateString())],
                [$book(2, now()->subMonth()->toDateString())],
                null,
            ]);

        $releases = app(LibraryReleases::class)->getBookReleases($user);

        expect(array_map(fn ($release) => [$release->getId(), $release->getAuthor()], $releases))
            ->toBe([[2, 'Ursula K. Le Guin'], [1, 'Frank Herbert']]);
    });
});

describe('LibraryBookService', function () {
    it('keeps releases fetched on a cache miss for seven days', function () {
        $user = makeUser();

        $this->mock(LibraryReleases::class)->shouldReceive('getBookReleases')->once()->andReturn(['release']);

        app(LibraryBookService::class)->releases($user);

        $this->travel(604799)->seconds();
        expect(Cache::store('user_data')->get('book_releases_'.$user->id))->toBe(['release']);

        $this->travel(2)->seconds();
        expect(Cache::store('user_data')->get('book_releases_'.$user->id))->toBeNull();
    });
});
