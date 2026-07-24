<?php

namespace App\Api\Libraries\Requests\Recipes;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryRecipeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string',
            'rating' => 'sometimes|nullable|integer|min:1|max:5',
            'calories' => 'sometimes|nullable|integer|min:0',
            'time_to_make' => 'sometimes|nullable|integer',
            'servings' => 'sometimes|nullable|integer|min:1',
            'cover_path' => 'sometimes|nullable|string',
            'link' => 'sometimes|nullable|url',
            'description' => 'sometimes|nullable|string',
            'steps' => 'sometimes|nullable|array',
            'steps.*' => 'required|string',
            'ingredients' => 'sometimes|nullable|array',
            'ingredients.*.name' => 'required|string',
            'ingredients.*.amount' => 'nullable|numeric|min:0',
            'ingredients.*.unit' => 'nullable|string',
            'type' => 'sometimes|nullable|in:breakfast,lunch,dinner,snack,dessert,drink,other',
        ];
    }
}
