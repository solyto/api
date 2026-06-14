<?php

namespace App\Api\Libraries\DTOs;

readonly class MusicSearchResultDTO
{
    public function __construct(
        private int     $id,
        private string  $title,
        private ?string $artist,
        private ?string $cover,
        private ?int    $releaseYear,
        private string  $provider,
        private string  $url,
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getArtist(): ?string
    {
        return $this->artist;
    }

    public function getCover(): ?string
    {
        return $this->cover;
    }

    public function getReleaseYear(): ?int
    {
        return $this->releaseYear;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}
