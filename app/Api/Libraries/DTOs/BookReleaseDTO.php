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
        private ?string $authorPhoto = null,
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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Set by the external service (e.g. Hardcover's own contributor id) until
     * LibraryBookService::import() resolves/creates the local Author record and
     * rewrites this to that Author's local id via withAuthorId().
     */
    public function getAuthorId(): ?int
    {
        return $this->authorId;
    }

    public function getAuthorPhoto(): ?string
    {
        return $this->authorPhoto;
    }

    public function withAuthorId(?int $authorId): self
    {
        return new self(
            title: $this->title,
            author: $this->author,
            url: $this->url,
            provider: $this->provider,
            id: $this->id,
            description: $this->description,
            authorId: $authorId,
            pageCount: $this->pageCount,
            cover: $this->cover,
            releaseDate: $this->releaseDate,
            authorPhoto: $this->authorPhoto,
        );
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
