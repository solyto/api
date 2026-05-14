<?php

namespace App\Api\Libraries\DTOs;

use Carbon\Carbon;

readonly class TmdbMovieDTO
{
    public function __construct(
        private int $id,
        private string $title,
        private ?string $overview,
        private ?string $poster,
        private Carbon $releaseDate,
        private string $type,
        private string $url,
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getOverview(): ?string
    {
        return $this->overview;
    }

    public function getPoster(): ?string
    {
        return $this->poster;
    }

    public function getReleaseDate(): Carbon
    {
        return $this->releaseDate;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}
