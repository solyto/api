<?php

namespace App\Api\Libraries\DTOs;

use Carbon\Carbon;

readonly class DeezerAlbumDTO
{
    public function __construct(
        private int    $id,
        private string $artist,
        private int    $artistId,
        private string $title,
        private string $url,
        private string $cover,
        private Carbon $releaseDate,
        private array $genres = [],
        private ?string $recordType = null,
    ) {}

    public function getRecordType(): ?string
    {
        return $this->recordType;
    }

    public function getGenres(): array
    {
        return $this->genres;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getArtist(): string
    {
        return $this->artist;
    }

    public function getArtistId(): int
    {
        return $this->artistId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getCover(): string
    {
        return $this->cover;
    }

    public function getReleaseDate(): Carbon
    {
        return $this->releaseDate;
    }
}
