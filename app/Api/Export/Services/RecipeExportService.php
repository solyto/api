<?php

namespace App\Api\Export\Services;

use App\Api\Libraries\Models\LibraryRecipe;
use App\Api\Users\Models\User;

class RecipeExportService
{
    public function export(User $user, string $path): void
    {
        $recipes = LibraryRecipe::forUser($user->id)->get();

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Title', 'Description', 'Ingredients', 'Steps', 'Type', 'Time To Make', 'Servings', 'Calories', 'Rating', 'Link',
        ]);

        foreach ($recipes as $recipe) {
            fputcsv($handle, [
                $recipe->title,
                $recipe->description,
                $this->formatIngredients($recipe->ingredients),
                collect($recipe->steps ?? [])->implode("\n"),
                $recipe->type,
                $recipe->time_to_make,
                $recipe->servings,
                $recipe->calories,
                $recipe->rating,
                $recipe->link,
            ]);
        }

        fclose($handle);
    }

    /**
     * Flatten a structured ingredient list into a readable, newline separated string.
     */
    private function formatIngredients(?array $ingredients): string
    {
        return collect($ingredients ?? [])
            ->map(fn ($item) => trim(trim(($item['amount'] ?? '').' '.($item['unit'] ?? '')).' '.($item['name'] ?? '')))
            ->filter()
            ->implode("\n");
    }
}
