<?php

namespace App\Api\Libraries\DTOs;

use Carbon\Carbon;

readonly class MusicReleaseDTO
{
    public function __construct(
        private int     $id,
        private ?string $artist,
        private ?int    $artistId,
        private string  $title,
        private string  $url,
        private ?string $cover,
        private string $provider,
        private ?Carbon $releaseDate = null,
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

    public function getArtist(): ?string
    {
        return $this->artist;
    }

    public function getArtistId(): ?int
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

    public function getCover(): ?string
    {
        return $this->cover;
    }

    public function getReleaseDate(): ?Carbon
    {
        return $this->releaseDate;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }
}
