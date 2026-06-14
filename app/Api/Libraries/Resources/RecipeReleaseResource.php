<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="RecipeReleaseImport",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="url", type="string", format="uri"),
 *     @OA\Property(property="provider", type="string"),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="time_to_make", type="integer", nullable=true),
 *     @OA\Property(property="rating", type="number", nullable=true),
 *     @OA\Property(property="ingredients", type="string", nullable=true),
 *     @OA\Property(property="instructions", type="string", nullable=true),
 *     @OA\Property(property="servings", type="integer", nullable=true),
 *     @OA\Property(property="tags", type="array", @OA\Items(type="string"))
 * )
 */
class RecipeReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'url' => $this->getUrl(),
            'provider' => $this->getProvider(),
            'cover' => $this->getCover(),
            'description' => $this->getDescription(),
            'time_to_make' => $this->getTimeToMake(),
            'rating' => $this->getRating(),
            'ingredients' => $this->getIngredients(),
            'instructions' => $this->getInstructions(),
            'servings' => $this->getServings(),
            'tags' => $this->getTags(),
        ];
    }
}
