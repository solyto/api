<?php

namespace App\Api\Libraries\Requests\Recipes;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryRecipeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'rating' => 'sometimes|nullable|integer|min:1|max:5',
            'time_to_make' => 'sometimes|nullable|integer',
            'cover_path' => 'sometimes|nullable|string|max:255',
            'link' => 'sometimes|nullable|url|max:255',
            'description' => 'sometimes|nullable|string',
            'ingredients' => 'sometimes|nullable|string|max:255',
            'type' => 'sometimes|nullable|in:breakfast,lunch,dinner,snack,dessert,drink,other',
        ];
    }
}
