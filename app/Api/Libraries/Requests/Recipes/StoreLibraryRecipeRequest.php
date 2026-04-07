<?php

namespace App\Api\Libraries\Requests\Recipes;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryRecipeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'rating' => 'nullable|integer|min:1|max:5',
            'time_to_make' => 'nullable|integer',
            'cover_path' => 'nullable|string|max:255',
            'link' => 'nullable|url|max:255',
            'description' => 'nullable|string',
            'ingredients' => 'nullable|string|max:255',
            'type' => 'nullable|in:breakfast,lunch,dinner,snack,dessert,drink,other',
        ];
    }
}
