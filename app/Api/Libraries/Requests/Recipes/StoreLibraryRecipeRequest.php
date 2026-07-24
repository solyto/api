<?php

namespace App\Api\Libraries\Requests\Recipes;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryRecipeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'rating' => 'nullable|integer|min:1|max:5',
            'calories' => 'nullable|integer|min:0',
            'time_to_make' => 'nullable|integer',
            'servings' => 'nullable|integer|min:1',
            'cover_path' => 'nullable|string',
            'link' => 'nullable|url',
            'description' => 'nullable|string',
            'steps' => 'nullable|array',
            'steps.*' => 'required|string',
            'ingredients' => 'nullable|array',
            'ingredients.*.name' => 'required|string',
            'ingredients.*.amount' => 'nullable|numeric|min:0',
            'ingredients.*.unit' => 'nullable|string',
            'type' => 'nullable|in:breakfast,lunch,dinner,snack,dessert,drink,other',
        ];
    }
}
