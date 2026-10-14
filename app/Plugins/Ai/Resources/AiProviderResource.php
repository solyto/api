<?php

namespace App\Plugins\Ai\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="AiProvider",
 *
 *     @OA\Property(property="provider", type="string", enum={"openai","openrouter","mistral"}),
 *     @OA\Property(property="name", type="string", example="OpenAI"),
 *     @OA\Property(
 *         property="models",
 *         type="array",
 *
 *         @OA\Items(
 *
 *             @OA\Property(property="id", type="string"),
 *             @OA\Property(property="name", type="string")
 *         )
 *     )
 * )
 */
class AiProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'provider' => $this->resource['provider']->value,
            'name' => $this->resource['provider']->label(),
            'models' => $this->resource['models'],
        ];
    }
}
