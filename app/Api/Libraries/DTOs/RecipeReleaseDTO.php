<?php

namespace App\Api\Libraries\DTOs;

readonly class RecipeReleaseDTO
{
    public function __construct(
        private int $id,
        private string $title,
        private string $url,
        private string $provider,
        private ?string $cover = null,
        private ?string $description = null,
        private ?int $timeToMake = null,
        private ?float $rating = null,
        private array $ingredients = [],
        private array $steps = [],
        private ?int $servings = null,
        private array $tags = [],
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

    public function getTimeToMake(): ?int
    {
        return $this->timeToMake;
    }

    public function getRating(): ?float
    {
        return $this->rating;
    }

    public function getIngredients(): array
    {
        return $this->ingredients;
    }

    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getServings(): ?int
    {
        return $this->servings;
    }

    public function getTags(): array
    {
        return $this->tags;
    }
}
