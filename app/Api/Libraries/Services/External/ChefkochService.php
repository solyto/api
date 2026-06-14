<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\RecipeReleaseDTO;
use App\Api\Libraries\Enums\RecipeServiceEnum;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ChefkochService
{
    private const string GET_RECIPE_URL = 'https://api.chefkoch.de/v2/recipes/%d';
    private const string SEARCH_URL = 'https://api.chefkoch.de/v2/api-gateway/recipes/v1/search';

    public function importFromUrl(string $url): ?RecipeReleaseDTO
    {
        $recipeId = $this->getRecipeIdFromUrl($url);
        $result = $this->getRecipe($recipeId);

        if (! $result) {
            return null;
        }

        $ingredients = collect($result['ingredientGroups'] ?? [])
            ->flatMap(fn ($group) => $group['ingredients'] ?? [])
            ->map(fn ($ingredient) => trim(
                $this->formatAmount($ingredient['amount'] ?? 0, $ingredient['unit'] ?? '').
                ' '.($ingredient['name'] ?? '')
            ))
            ->filter()
            ->implode("\n");

        $cover = null;
        if (! empty($result['previewImageUrlTemplate'])) {
            $cover = str_replace('<format>', 'crop-960x640', $result['previewImageUrlTemplate']);
        }

        return new RecipeReleaseDTO(
            id: (int) $result['id'],
            title: $result['title'],
            url: $result['siteUrl'],
            provider: RecipeServiceEnum::CHEFKOCH->value,
            cover: $cover,
            description: $result['subtitle'] ?? null,
            timeToMake: $result['totalTime'] ?? null,
            rating: $result['rating']['rating'] ?? null,
            ingredients: $ingredients ?: null,
            instructions: $result['instructions'] ?? null,
            servings: $result['servings'] ?? null,
            tags: $result['tags'] ?? [],
        );
    }

    public function searchRecipes(string $query): ?array
    {
        try {
            $response = Http::get(self::SEARCH_URL, [
                'query' => $query,
                'limit' => 10,
            ]);

            if (! $response->successful()) {
                return null;
            }

            return $response->json()['results'] ?? null;
        } catch (ConnectionException) {
            return null;
        }
    }

    private function getRecipe(int $recipeId): ?array
    {
        try {
            $response = Http::get(sprintf(self::GET_RECIPE_URL, $recipeId));

            if (! $response->successful()) {
                return null;
            }

            return $response->json();
        } catch (ConnectionException) {
            return null;
        }
    }

    private function getRecipeIdFromUrl(string $url): int
    {
        $path = parse_url($url, PHP_URL_PATH);
        preg_match('/(\d+)/', $path, $matches);

        return (int) ($matches[1] ?? 0);
    }

    private function formatAmount(float $amount, string $unit): string
    {
        if ($amount == 0 && empty($unit)) {
            return '';
        }

        $formatted = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');

        return $formatted.($unit ? ' '.$unit : '');
    }
}
