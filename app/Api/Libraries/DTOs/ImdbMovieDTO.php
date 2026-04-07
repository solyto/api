<?php

namespace App\Api\Libraries\DTOs;

class ImdbMovieDTO
{
    public function __construct(
        private string $id,
        private string $type,
        private string $title,
        private string $description,
        private ?string $cover,
        private int $releaseYear,
        private ?int $runtime,
        private array $genres = []
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
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
}
