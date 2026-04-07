<?php

namespace App\Api\Libraries\DTOs;

use Carbon\Carbon;

readonly class HardcoverBookDTO
{
    public function __construct(
        private int     $id,
        private string  $title,
        private ?string $description,
        private string  $author,
        private ?int    $authorId,
        private ?int    $pageCount,
        private ?string $cover,
        private string  $url,
        private ?Carbon $releaseDate,
    )
    {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getAuthor(): string
    {
        return $this->author;
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

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getReleaseDate(): ?Carbon
    {
        return $this->releaseDate;
    }
}
