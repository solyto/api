<?php

namespace App\Api\CheckIn\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCheckInSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        $validTrackers = implode(',', ['mood', 'sports', 'water', 'sleep', 'dreams', 'work', 'food_quality', 'food_amount', 'menstruation', 'alcohol', 'smoking']);
        $validSports = implode(',', ['dumbbell', 'bike', 'mountain', 'footprints', 'waves_ladder', 'yoga']);

        return [
            'enabled_trackers'   => 'required|array|min:1',
            'enabled_trackers.*' => 'string|in:' . $validTrackers,
            'selected_sports'    => 'required|array|size:5',
            'selected_sports.*'  => 'string|in:' . $validSports,
        ];
    }
}
