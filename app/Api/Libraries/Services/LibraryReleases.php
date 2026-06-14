<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\DTOs\BookReleaseDTO;
use App\Api\Libraries\DTOs\MusicReleaseDTO;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Services\External\DeezerService;
use App\Api\Libraries\Services\External\HardcoverService;
use App\Api\Libraries\Services\External\TmdbService;
use App\Api\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LibraryReleases
{
    public function __construct(
        private readonly DeezerService $deezerService,
        private readonly HardcoverService $hardcoverService,
        private readonly TmdbService $tmdbService,
        private readonly User $user
    ) {}

    public function getMusicReleases(): array
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
                    releaseDate: Carbon::createFromFormat('Y-m-d', $release['release_date'])
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
