<?php

namespace App\Plugins\Ai\Services;

use App\Plugins\Ai\Enums\AiProviderEnum;
use Illuminate\Support\Facades\File;

class AiModelSnapshot
{
    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? resource_path('data/ai-models.json');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function models(AiProviderEnum $provider): array
    {
        return $this->read()[$provider->value] ?? [];
    }

    public function has(AiProviderEnum $provider, string $model): bool
    {
        return in_array($model, array_column($this->models($provider), 'id'), true);
    }

    /**
     * @return array<string, array<int, array{id: string, name: string}>>
     */
    private function read(): array
    {
        return File::json($this->path, JSON_THROW_ON_ERROR);
    }
}
