<?php

namespace App\Api\Libraries\DTOs;

use Carbon\Carbon;

readonly class BookReleaseDTO
{
    public function __construct(
        private string  $title,
        private string  $author,
        private string  $url,
        private string  $provider,
        private ?int    $id = null,
        private ?string $description = null,
        private ?int    $authorId = null,
        private ?int    $pageCount = null,
        private ?string $cover = null,
        private ?Carbon $releaseDate = null,
    )
    {}

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getAuthorId(): int
    {
        return $this->authorId;
    }

    public function getPageCount(): ?int
    {
        return $this->pageCount;
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
