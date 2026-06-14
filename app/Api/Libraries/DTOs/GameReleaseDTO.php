<?php

namespace App\Api\Libraries\DTOs;

readonly class GameReleaseDTO
{
    public function __construct(
        private int     $id,
        private string  $title,
        private string  $url,
        private string  $provider,
        private ?string $cover = null,
        private ?string $description = null,
        private ?int    $publicationYear = null,
        private ?string $developer = null,
        private ?string $publisher = null,
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

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getCover(): ?string
    {
        return $this->cover;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getPublicationYear(): ?int
    {
        return $this->publicationYear;
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
