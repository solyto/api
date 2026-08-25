<?php

use App\Api\Libraries\DTOs\MovieReleaseDTO;
use App\Api\Libraries\Jobs\GrabBookReleases;
use App\Api\Libraries\Jobs\GrabMovieReleases;
use App\Api\Libraries\Jobs\GrabMusicReleases;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryMovieGenre;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Services\External\DeezerService;
use App\Api\Libraries\Services\External\HardcoverService;
use App\Api\Libraries\Services\External\TmdbService;
use App\Shared\Services\UserCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

describe('GrabMusicReleases', function () {
    beforeEach(function () {
        $this->user = makeUser();
        LibraryMusic::factory()->forUser($this->user)->create(['rating' => 5, 'artist' => 'Radiohead']);

        $deezer = $this->mock(DeezerService::class);
        $deezer->shouldReceive('searchArtists')->with('Radiohead')->andReturn([['id' => 6]]);
        $deezer->shouldReceive('getNewReleases')->with(6)->andReturn([[
            'id' => 10,
            'title' => 'Fresh Album',
            'link' => 'https://deezer.com/album/10',
            'cover_big' => 'https://cover.example/10.jpg',
            'release_date' => now()->toDateString(),
        ]]);
    });

    it('notifies a new release and remembers it in the long-term cache', function () {
        Notification::fake();

        app(GrabMusicReleases::class)->handle(app(UserCacheService::class));

        Notification::assertSentTo($this->user, \App\Api\Libraries\Notifications\MusicReleaseNotification::class);

        $notified = Cache::store('longterm')->get('music_release_notified_'.$this->user->id);
        expect($notified)->toBe([10]);
    });

    it('does not re-notify a release already in the notified set', function () {
        Notification::fake();
        Cache::store('longterm')->put('music_release_notified_'.$this->user->id, [10]);

        app(GrabMusicReleases::class)->handle(app(UserCacheService::class));

        Notification::assertNotSentTo($this->user, \App\Api\Libraries\Notifications\MusicReleaseNotification::class);

        $notified = Cache::store('longterm')->get('music_release_notified_'.$this->user->id);
        expect($notified)->toBe([10]);
    });

    it('notifies only the releases not yet in the notified set', function () {
        Notification::fake();
        Cache::store('longterm')->put('music_release_notified_'.$this->user->id, [10]);

        app(GrabMusicReleases::class)->handle(app(UserCacheService::class));

        Notification::assertNotSentTo($this->user, \App\Api\Libraries\Notifications\MusicReleaseNotification::class);
    });
});

describe('GrabBookReleases', function () {
    beforeEach(function () {
        $this->user = makeUser();
        LibraryBook::factory()->forUser($this->user)->create(['rating' => 5, 'author' => 'Frank Herbert']);

        $hardcover = $this->mock(HardcoverService::class);
        $hardcover->shouldReceive('getNewReleases')->with('Frank Herbert')->andReturn([[
            'id' => 20,
            'slug' => 'children-of-dune',
            'title' => 'Children of Dune',
            'release_date' => now()->toDateString(),
            'description' => 'Third book',
            'pages' => 444,
            'image' => ['url' => 'https://cover.example/cod.jpg'],
            'contributions' => [['author' => ['id' => 1, 'name' => 'Frank Herbert']]],
        ]]);
    });

    it('notifies a new release and remembers it in the long-term cache', function () {
        Notification::fake();

        app(GrabBookReleases::class)->handle(app(UserCacheService::class));

        Notification::assertSentTo($this->user, \App\Api\Libraries\Notifications\BookReleaseNotification::class);

        $notified = Cache::store('longterm')->get('book_release_notified_'.$this->user->id);
        expect($notified)->toBe([20]);
    });

    it('does not re-notify a release already in the notified set', function () {
        Notification::fake();
        Cache::store('longterm')->put('book_release_notified_'.$this->user->id, [20]);

        app(GrabBookReleases::class)->handle(app(UserCacheService::class));

        Notification::assertNotSentTo($this->user, \App\Api\Libraries\Notifications\BookReleaseNotification::class);

        $notified = Cache::store('longterm')->get('book_release_notified_'.$this->user->id);
        expect($notified)->toBe([20]);
    });
});

describe('GrabMovieReleases', function () {
    beforeEach(function () {
        $this->user = makeUser();
        $movie = LibraryMovie::factory()->forUser($this->user)->highRated()->create(['title' => 'Favorite Movie']);
        $genre = LibraryMovieGenre::factory()->forUser($this->user)->create(['title' => 'Science Fiction']);
        $movie->genres()->attach($genre);

        $tmdb = $this->mock(TmdbService::class);
        $tmdb->shouldReceive('getReleasesForGenres')->andReturn([
            new MovieReleaseDTO(
                id: 'tt123',
                title: 'New Movie',
                url: 'https://example.com/tt123',
                provider: 'tmdb',
                type: 'movie',
            ),
        ]);
    });

    it('notifies a new release and remembers it in the long-term cache', function () {
        Notification::fake();

        app(GrabMovieReleases::class)->handle(app(UserCacheService::class));

        Notification::assertSentTo($this->user, \App\Api\Libraries\Notifications\MovieReleaseNotification::class);

        $notified = Cache::store('longterm')->get('movie_release_notified_'.$this->user->id);
        expect($notified)->toBe(['tt123']);
    });

    it('does not re-notify a release already in the notified set', function () {
        Notification::fake();
        Cache::store('longterm')->put('movie_release_notified_'.$this->user->id, ['tt123']);

        app(GrabMovieReleases::class)->handle(app(UserCacheService::class));

        Notification::assertNotSentTo($this->user, \App\Api\Libraries\Notifications\MovieReleaseNotification::class);

        $notified = Cache::store('longterm')->get('movie_release_notified_'.$this->user->id);
        expect($notified)->toBe(['tt123']);
    });
});
