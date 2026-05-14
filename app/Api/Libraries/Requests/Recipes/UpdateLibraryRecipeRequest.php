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
            'time_to_make' => 'sometimes|nullable|integer',
            'cover_path' => 'sometimes|nullable|string',
            'link' => 'sometimes|nullable|url',
            'description' => 'sometimes|nullable|string',
            'ingredients' => 'sometimes|nullable|string',
            'type' => 'sometimes|nullable|in:breakfast,lunch,dinner,snack,dessert,drink,other',
        ];
    }
}
