<?php

namespace App\Api\Calendars\Requests;

use App\Api\Users\Models\Friend;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShareCalendarRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'friend_id' => 'required|uuid|exists:users,id',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $user = $this->user();
                $friendId = $this->input('friend_id');

                if ($friendId === $user->id) {
                    $validator->errors()->add('friend_id', 'You cannot share a calendar with yourself.');
                    return;
                }

                $isFriend = Friend::where(function ($query) use ($user, $friendId) {
                    $query->where('user_id_1', $user->id)->where('user_id_2', $friendId);
                })->orWhere(function ($query) use ($user, $friendId) {
                    $query->where('user_id_1', $friendId)->where('user_id_2', $user->id);
                })->exists();

                if (!$isFriend) {
                    $validator->errors()->add('friend_id', 'You can only share calendars with friends.');
                }
            }
        ];
    }
}
