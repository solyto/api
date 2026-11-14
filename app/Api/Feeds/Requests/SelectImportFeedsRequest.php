<?php

namespace App\Api\Feeds\Requests;

use App\Api\Feeds\Services\OpmlImportService;
use Illuminate\Foundation\Http\FormRequest;

class SelectImportFeedsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'urls' => 'required|array|min:1|max:'.OpmlImportService::MAX_FEEDS,
            'urls.*' => 'required|string',
        ];
    }
}
