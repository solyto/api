<?php

namespace App\Api\Libraries\Requests\Plants;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryPlantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'latin_name' => 'sometimes|nullable|string|max:255',
            'location' => 'sometimes|nullable|string|in:indoor,outdoor,both',
            'sunlight' => 'sometimes|nullable|string|in:full_sun,partial_sun,indirect,shade',
            'current_size' => 'sometimes|nullable|string|max:255',
            'max_size' => 'sometimes|nullable|string|max:255',
            'acquired_at' => 'sometimes|nullable|date',
            'winter_hardy' => 'sometimes|nullable|boolean',
            'instructions' => 'sometimes|nullable|string',
            'cover_path' => 'sometimes|nullable|url|max:255',
            'link' => 'sometimes|nullable|url|max:255',
        ];
    }
}
