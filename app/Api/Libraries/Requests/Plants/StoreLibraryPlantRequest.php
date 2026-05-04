<?php

namespace App\Api\Libraries\Requests\Plants;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryPlantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'latin_name' => 'nullable|string|max:255',
            'location' => 'nullable|string|in:indoor,outdoor,both',
            'sunlight' => 'nullable|string|in:full_sun,partial_sun,indirect,shade',
            'current_size' => 'nullable|string|max:255',
            'max_size' => 'nullable|string|max:255',
            'acquired_at' => 'nullable|date',
            'winter_hardy' => 'nullable|boolean',
            'instructions' => 'nullable|string',
            'cover_path' => 'nullable|url|max:255',
            'link' => 'nullable|url|max:255',
            'wishlist' => 'nullable|boolean',
        ];
    }
}
