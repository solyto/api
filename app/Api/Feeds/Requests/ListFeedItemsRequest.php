<?php

namespace App\Api\Feeds\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListFeedItemsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'feed_id' => 'sometimes|uuid',
        ];
    }
}
