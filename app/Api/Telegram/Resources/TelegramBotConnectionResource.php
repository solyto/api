<?php

namespace App\Api\Telegram\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="TelegramBotConnection",
 *
 *     @OA\Property(property="token", type="string"),
 *     @OA\Property(property="is_confirmed", type="boolean"),
 *     @OA\Property(property="chat_id", type="integer", nullable=true),
 *     @OA\Property(property="your_day_alert", type="boolean"),
 *     @OA\Property(property="check_in_alert", type="boolean")
 * )
 */
class TelegramBotConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'is_confirmed' => $this->is_confirmed,
            'chat_id' => $this->chat_id,
            'your_day_alert' => $this->your_day_alert,
            'check_in_alert' => $this->check_in_alert,
        ];
    }
}
