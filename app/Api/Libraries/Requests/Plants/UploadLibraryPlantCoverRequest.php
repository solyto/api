<?php

namespace App\Api\Libraries\Requests\Plants;

use Illuminate\Foundation\Http\FormRequest;

class UploadLibraryPlantCoverRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:1024',
        ];
    }
}
