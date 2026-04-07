<?php

namespace App\Api\Libraries\DTOs;

readonly class SteamGameDTO
{
    public function __construct(
        private int     $id,
        private string  $title,
        private string  $url,
        private ?string $cover,
        private ?string $description,
        private ?string $releaseDate,
        private ?string $developer,
        private ?string $publisher,
        private array   $genres = [],
    ) {}

    public function getId(): int
    {
        return $this->id;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getReleaseDate(): ?string
    {
        return $this->releaseDate;
    }

    public function getDeveloper(): ?string
    {
        return $this->developer;
    }

    public function getPublisher(): ?string
    {
        return $this->publisher;
    }

    public function getGenres(): array
    {
        return $this->genres;
    }
}
