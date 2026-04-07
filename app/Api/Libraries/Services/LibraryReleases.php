<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\DTOs\DeezerAlbumDTO;
use App\Api\Libraries\DTOs\HardcoverBookDTO;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Services\External\DeezerApiService;
use App\Api\Libraries\Services\External\HardcoverApiService;
use App\Api\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LibraryReleases
{
    public function __construct(
        private readonly DeezerApiService $deezerApiService,
        private readonly HardcoverApiService $hardcoverApiService,
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
            $search = $this->deezerApiService->searchArtists($artist);

            if (!$search) {
                continue;
            }

            $artistId = $search[0]['id'];
            $artistReleases = $this->deezerApiService->getNewReleases($artistId);

            if (!$artistReleases || count($artistReleases) === 0) {
                continue;
            }

            foreach ($artistReleases as $release) {
                $releases[] = new DeezerAlbumDTO(
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
            $authorReleases = $this->hardcoverApiService->getNewReleases($author);

            if (!$authorReleases) {
                continue;
            }

            foreach ($authorReleases as $release) {
                $releases[] = new HardcoverBookDTO(
                    id: $release['id'],
                    title: $release['title'],
                    description: $release['description'],
                    author: $author,
                    authorId: $release['contributions'][0]['author']['id'] ?? null,
                    pageCount: $release['pages'],
                    cover: $release['image']['url'] ?? null,
                    url: 'https://hardcover.app/books/' . $release['slug'],
                    releaseDate: Carbon::createFromFormat('Y-m-d', $release['release_date'])
                );
            }
        }

        usort($releases, fn ($a, $b) => $b->getReleaseDate()->timestamp <=> $a->getReleaseDate()->timestamp);

        return $releases;
    }
}
