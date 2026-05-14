<?php

namespace App\Api\Libraries\Requests\Plants;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryPlantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'nullable|string',
            'latin_name' => 'nullable|string',
            'location' => 'nullable|string|in:indoor,outdoor,both',
            'sunlight' => 'nullable|string|in:full_sun,partial_sun,indirect,shade',
            'current_size' => 'nullable|string',
            'max_size' => 'nullable|string',
            'acquired_at' => 'nullable|date',
            'winter_hardy' => 'nullable|boolean',
            'instructions' => 'nullable|string',
            'cover_path' => 'nullable|url',
            'link' => 'nullable|url',
        ];
    }
}
