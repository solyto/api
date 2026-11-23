<?php

use App\Api\Libraries\DTOs\MovieReleaseDTO;
use App\Api\Libraries\Jobs\GrabBookReleases;
use App\Api\Libraries\Jobs\GrabMovieReleases;
use App\Api\Libraries\Jobs\GrabMusicReleases;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryMovieGenre;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Notifications\BookReleaseNotification;
use App\Api\Libraries\Notifications\MovieReleaseNotification;
use App\Api\Libraries\Notifications\MusicReleaseNotification;
use App\Api\Libraries\Services\External\DeezerService;
use App\Api\Libraries\Services\External\HardcoverService;
use App\Api\Libraries\Services\External\TmdbService;
use App\Api\Libraries\Services\LibraryReleases;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

function grabReleases(string $job): void
{
    app($job)->handle(app(UserCacheService::class), app(LibraryReleases::class));
}

function deezerRelease(int|string $id): array
{
    return [
        'id' => $id,
        'title' => 'Album '.$id,
        'link' => 'https://deezer.com/album/'.$id,
        'cover_big' => null,
        'release_date' => now()->toDateString(),
    ];
}

describe('GrabMusicReleases', function () {
    beforeEach(function () {
        $this->user = makeUser();
        LibraryMusic::factory()->forUser($this->user)->create(['rating' => 5, 'artist' => 'Radiohead']);

        $deezer = $this->mock(DeezerService::class);
        $deezer->shouldReceive('searchManyArtists')->with(['Radiohead'])->andReturn([[['id' => 6]]]);
        $deezer->shouldReceive('getNewReleasesForArtists')->with([6])->andReturn([6 => [deezerRelease(10)]]);
    });

    it('notifies a new release and remembers it in the long-term cache', function () {
        Notification::fake();

        grabReleases(GrabMusicReleases::class);

        Notification::assertSentTo($this->user, MusicReleaseNotification::class);

        $notified = Cache::store('longterm')->get('music_release_notified_'.$this->user->id);
        expect($notified)->toBe([10]);
    });

    it('does not re-notify a release already in the notified set', function () {
        Notification::fake();
        Cache::store('longterm')->put('music_release_notified_'.$this->user->id, [10]);

        grabReleases(GrabMusicReleases::class);

        Notification::assertNotSentTo($this->user, MusicReleaseNotification::class);

        $notified = Cache::store('longterm')->get('music_release_notified_'.$this->user->id);
        expect($notified)->toBe([10]);
    });

    it('notifies only the releases not yet in the notified set', function () {
        Notification::fake();
        Cache::store('longterm')->put('music_release_notified_'.$this->user->id, [10]);
        $deezer = $this->mock(DeezerService::class);
        $deezer->shouldReceive('searchManyArtists')->with(['Radiohead'])->andReturn([[['id' => 6]]]);
        $deezer->shouldReceive('getNewReleasesForArtists')->with([6])->andReturn([6 => [deezerRelease(10), deezerRelease(11)]]);

        grabReleases(GrabMusicReleases::class);

        Notification::assertSentToTimes($this->user, MusicReleaseNotification::class, 1);
        Notification::assertSentTo($this->user, MusicReleaseNotification::class, fn ($notification) => $notification->title === 'Album 11');
        expect(Cache::store('longterm')->get('music_release_notified_'.$this->user->id))->toBe([10, 11]);
    });

    it('tolerates string release ids (spotify) in the notified set', function () {
        Notification::fake();

        // The job's dedup compares strictly, so a string id must be stored and compared as a string.
        $deezer = $this->mock(DeezerService::class);
        $deezer->shouldReceive('searchManyArtists')->with(['Radiohead'])->andReturn([[['id' => 6]]]);
        $deezer->shouldReceive('getNewReleasesForArtists')->with([6])->andReturn([6 => [deezerRelease('4aawyAB9vmqN3uQ7FjRGTy')]]);

        grabReleases(GrabMusicReleases::class);

        Notification::assertSentTo($this->user, MusicReleaseNotification::class);

        $notified = Cache::store('longterm')->get('music_release_notified_'.$this->user->id);
        expect($notified)->toBe(['4aawyAB9vmqN3uQ7FjRGTy']);

        // A fresh fake counts only the second run's notifications.
        Notification::fake();

        grabReleases(GrabMusicReleases::class);

        Notification::assertNotSentTo($this->user, MusicReleaseNotification::class);
    });
});

describe('GrabBookReleases', function () {
    beforeEach(function () {
        $this->user = makeUser();
        LibraryBook::factory()->forUser($this->user)->create(['rating' => 5, 'author' => 'Frank Herbert']);

        $hardcover = $this->mock(HardcoverService::class);
        $hardcover->shouldReceive('getNewReleasesForAuthors')->with(['Frank Herbert'])->andReturn([[[
            'id' => 20,
            'slug' => 'children-of-dune',
            'title' => 'Children of Dune',
            'release_date' => now()->toDateString(),
            'description' => 'Third book',
            'pages' => 444,
            'image' => ['url' => 'https://cover.example/cod.jpg'],
            'contributions' => [['author' => ['id' => 1, 'name' => 'Frank Herbert']]],
        ]]]);
    });

    it('notifies a new release and remembers it in the long-term cache', function () {
        Notification::fake();

        grabReleases(GrabBookReleases::class);

        Notification::assertSentTo($this->user, BookReleaseNotification::class);

        $notified = Cache::store('longterm')->get('book_release_notified_'.$this->user->id);
        expect($notified)->toBe([20]);
    });

    it('does not re-notify a release already in the notified set', function () {
        Notification::fake();
        Cache::store('longterm')->put('book_release_notified_'.$this->user->id, [20]);

        grabReleases(GrabBookReleases::class);

        Notification::assertNotSentTo($this->user, BookReleaseNotification::class);

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

        grabReleases(GrabMovieReleases::class);

        Notification::assertSentTo($this->user, MovieReleaseNotification::class);

        $notified = Cache::store('longterm')->get('movie_release_notified_'.$this->user->id);
        expect($notified)->toBe(['tt123']);
    });

    it('does not re-notify a release already in the notified set', function () {
        Notification::fake();
        Cache::store('longterm')->put('movie_release_notified_'.$this->user->id, ['tt123']);

        grabReleases(GrabMovieReleases::class);

        Notification::assertNotSentTo($this->user, MovieReleaseNotification::class);

        $notified = Cache::store('longterm')->get('movie_release_notified_'.$this->user->id);
        expect($notified)->toBe(['tt123']);
    });
});
