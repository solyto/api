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
            'Title', 'Description', 'Ingredients', 'Type', 'Time To Make', 'Rating', 'Link',
        ]);

        foreach ($recipes as $recipe) {
            fputcsv($handle, [
                $recipe->title,
                $recipe->description,
                $recipe->ingredients,
                $recipe->type,
                $recipe->time_to_make,
                $recipe->rating,
                $recipe->link,
            ]);
        }

        fclose($handle);
    }
}
