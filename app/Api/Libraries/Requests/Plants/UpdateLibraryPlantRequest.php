<?php

namespace App\Api\Libraries\Requests\Plants;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryPlantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string',
            'latin_name' => 'sometimes|nullable|string',
            'location' => 'sometimes|nullable|string|in:indoor,outdoor,both',
            'sunlight' => 'sometimes|nullable|string|in:full_sun,partial_sun,indirect,shade',
            'current_size' => 'sometimes|nullable|string',
            'max_size' => 'sometimes|nullable|string',
            'acquired_at' => 'sometimes|nullable|date',
            'winter_hardy' => 'sometimes|nullable|boolean',
            'instructions' => 'sometimes|nullable|string',
            'cover_path' => 'sometimes|nullable|url',
            'link' => 'sometimes|nullable|url',
        ];
    }
}
