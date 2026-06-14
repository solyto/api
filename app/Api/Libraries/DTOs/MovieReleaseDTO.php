<?php

namespace App\Api\Libraries\DTOs;

readonly class MovieReleaseDTO
{
    public function __construct(
        private string $id,
        private string $title,
        private string $url,
        private string $provider,
        private ?string $type = null,
        private ?string $description = null,
        private ?string $cover = null,
        private ?int $releaseYear = null,
        private ?int $runtime = null,
        private array $genres = []
    ) {}

    public function getId(): string
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

    public function getType(): string
    {
        return $this->type;
    }

    public function getCover(): ?string
    {
        return $this->cover;
    }

    public function getReleaseYear(): int
    {
        return $this->releaseYear;
    }

    public function getRuntime(): ?int
    {
        return $this->runtime;
    }

    public function getGenres(): array
    {
        return $this->genres;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getLink(): string
    {
        return 'https://www.imdb.com/title/' . $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }
}
