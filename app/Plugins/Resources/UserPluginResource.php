<?php

namespace App\Plugins\Resources;

use App\Plugins\DTOs\UserPluginStateDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="UserPlugin",
 *
 *     @OA\Property(property="plugin", type="string", enum={"ai"}),
 *     @OA\Property(property="enabled", type="boolean"),
 *     @OA\Property(property="has_api_key", type="boolean", description="Whether a readable API key is stored. The key itself is never returned."),
 *     @OA\Property(
 *         property="settings",
 *         type="object",
 *         nullable=true,
 *
 *         @OA\Property(property="provider", type="string", enum={"openai","openrouter","mistral"}),
 *         @OA\Property(property="model", type="string")
 *     ),
 *     @OA\Property(property="model_available", type="boolean", description="Whether the stored model is in the current model snapshot")
 * )
 *
 * @mixin UserPluginStateDTO
 */
class UserPluginResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'plugin' => $this->plugin->value,
            'enabled' => $this->enabled,
            'has_api_key' => $this->hasApiKey,
            'settings' => $this->settings,
            'model_available' => $this->modelAvailable,
        ];
    }
}
