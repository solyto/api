<?php

namespace App\Api\Users\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="UserSettings",
 *
 *     @OA\Property(property="language", type="string", enum={"en","de","fr","es"}),
 *     @OA\Property(property="timezone", type="string"),
 *     @OA\Property(property="date_format", type="string"),
 *     @OA\Property(property="time_format", type="string"),
 *     @OA\Property(property="navigation", type="object", nullable=true),
 *     @OA\Property(property="ai_enabled", type="boolean"),
 *     @OA\Property(property="openai_api_key", type="string", nullable=true),
 *     @OA\Property(property="first_visit", type="boolean")
 * )
 */
class UserSettingsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'language' => $this->language,
            'timezone' => $this->timezone,
            'date_format' => $this->date_format,
            'time_format' => $this->time_format,
            'navigation' => $this->navigation,
            'ai_enabled' => $this->ai_enabled,
            'openai_api_key' => $this->openai_api_key,
            'first_visit' => $this->first_visit,
            'temperature_unit' => $this->temperature_unit ?? 'c'
        ];
    }
}
