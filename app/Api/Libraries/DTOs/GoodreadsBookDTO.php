<?php

namespace App\Api\Libraries\DTOs;

use Carbon\Carbon;

readonly class GoodreadsBookDTO
{
    public function __construct(
        private string  $title,
        private string  $author,
        private ?int    $pageCount,
        private ?string $cover,
        private string  $url,
        private ?Carbon $releaseDate,
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
