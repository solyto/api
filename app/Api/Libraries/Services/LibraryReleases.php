<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\DTOs\BookReleaseDTO;
use App\Api\Libraries\DTOs\MusicReleaseDTO;
use App\Api\Libraries\Enums\BookServiceEnum;
use App\Api\Libraries\Enums\MusicServiceEnum;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Services\External\DeezerService;
use App\Api\Libraries\Services\External\HardcoverService;
use App\Api\Libraries\Services\External\SpotifyService;
use App\Api\Libraries\Services\External\TmdbService;
use App\Api\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LibraryReleases
{
    public function __construct(
        private readonly DeezerService $deezerService,
        private readonly SpotifyService $spotifyService,
        private readonly HardcoverService $hardcoverService,
        private readonly TmdbService $tmdbService,
        private readonly User $user
    ) {}

    public function getMusicReleases(): array
    {
        // SWITCH (deferred decision, see docs/jobs/post_spotify-integration):
        // the Spotify releases path is built and tested but dormant. To serve
        // Spotify releases, replace the call below with getSpotifyMusicReleases()
        // (replace Deezer), merge both arrays (run both), or gate it behind a
        // feature toggle.
        return $this->getDeezerMusicReleases();
    }

    private function getDeezerMusicReleases(): array
    {
        $favorites = LibraryMusic::forUser($this->user->id)->where('rating', '>=', 4)->get();
        $processedArtists = [];
        $releases = [];

        foreach ($favorites as $favorite) {
            $artist = Str::contains($favorite->artist, ',') ? explode(',', $favorite->artist)[0] : $favorite->artist;

            if (in_array($artist, $processedArtists)) {
                continue;
            }

            $processedArtists[] = $artist;
            $search = $this->deezerService->searchArtists($artist);

            if (! $search) {
                continue;
            }

            $artistId = $search[0]['id'];
            $artistReleases = $this->deezerService->getNewReleases($artistId);

            if (! $artistReleases || count($artistReleases) === 0) {
                continue;
            }

            foreach ($artistReleases as $release) {
                $releases[] = new MusicReleaseDTO(
                    id: $release['id'],
                    artist: $artist,
                    artistId: $artistId,
                    title: $release['title'],
                    url: $release['link'],
                    cover: $release['cover_big'],
                    provider: MusicServiceEnum::DEEZER->value,
                    releaseDate: Carbon::createFromFormat('Y-m-d', $release['release_date'])
                );
            }
        }

        usort($releases, fn ($a, $b) => $b->getReleaseDate()->timestamp <=> $a->getReleaseDate()->timestamp);

        return $releases;
    }

    /**
     * Dormant Spotify releases path: mirrors getDeezerMusicReleases() but
     * resolves favorite artists via Spotify and maps simplified album objects
     * (string ids, precision-aware release dates) into MusicReleaseDTOs with
     * provider=spotify. Not wired into getMusicReleases() yet.
     */
    public function getSpotifyMusicReleases(): array
    {
        $favorites = LibraryMusic::forUser($this->user->id)->where('rating', '>=', 4)->get();
        $processedArtists = [];
        $releases = [];

        foreach ($favorites as $favorite) {
            $artist = Str::contains($favorite->artist, ',') ? explode(',', $favorite->artist)[0] : $favorite->artist;

            if (in_array($artist, $processedArtists)) {
                continue;
            }

            $processedArtists[] = $artist;
            $search = $this->spotifyService->searchArtists($artist);

            if (! $search) {
                continue;
            }

            $artistId = $search[0]['id'];
            $artistReleases = $this->spotifyService->getNewReleases($artistId);

            if (! $artistReleases || count($artistReleases) === 0) {
                continue;
            }

            foreach ($artistReleases as $release) {
                $releases[] = new MusicReleaseDTO(
                    id: $release['id'],
                    artist: $artist,
                    artistId: $artistId,
                    title: $release['name'],
                    url: sprintf('https://open.spotify.com/album/%s', $release['id']),
                    cover: $release['images'][0]['url'] ?? null,
                    provider: MusicServiceEnum::SPOTIFY->value,
                    releaseDate: SpotifyService::parseReleaseDate($release['release_date'] ?? null, $release['release_date_precision'] ?? null)
                );
            }
        }

        usort($releases, fn ($a, $b) => $b->getReleaseDate()->timestamp <=> $a->getReleaseDate()->timestamp);

        return $releases;
    }

    public function getBookReleases(): array
    {
        $favorites = LibraryBook::forUser($this->user->id)->where('rating', '>=', 4)->get();
        $processedAuthors = [];
        $releases = [];

        foreach ($favorites as $favorite) {
            $author = Str::contains($favorite->author, ',') ? explode(',', $favorite->author)[0] : $favorite->author;

            if (in_array($author, $processedAuthors)) {
                continue;
            }

            $processedAuthors[] = $author;
            $authorReleases = $this->hardcoverService->getNewReleases($author);

            if (! $authorReleases) {
                continue;
            }

            foreach ($authorReleases as $release) {
                $releases[] = new BookReleaseDTO(
                    title: $release['title'],
                    author: $author,
                    url: HardcoverService::getReleaseUrl($release['slug']),
                    provider: BookServiceEnum::HARDCOVER->value,
                    id: $release['id'],
                    description: $release['description'],
                    authorId: $release['contributions'][0]['author']['id'] ?? null,
                    pageCount: $release['pages'],
                    cover: $release['image']['url'] ?? null,
                    releaseDate: $release['release_date'] ? Carbon::createFromFormat('Y-m-d', $release['release_date']) : null
                );
            }
        }

        usort($releases, fn ($a, $b) => $b->getReleaseDate()->timestamp <=> $a->getReleaseDate()->timestamp);

        return $releases;
    }

    public function getMovieReleases(): array
    {
        $favorites = LibraryMovie::forUser($this->user->id)->where('rating', '>=', 4)->with('genres')->get();

        $genreNames = $favorites
            ->flatMap(fn ($m) => $m->genres->pluck('title'))
            ->unique()
            ->values()
            ->toArray();

        return $this->tmdbService->getReleasesForGenres($genreNames);
    }
}
