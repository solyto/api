<?php

namespace App\Api\Libraries\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="LibraryRecipe",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="rating", type="number", nullable=true),
 *     @OA\Property(property="calories", type="integer", nullable=true),
 *     @OA\Property(property="time_to_make", type="string", nullable=true),
 *     @OA\Property(property="servings", type="integer", nullable=true),
 *     @OA\Property(property="cover", type="string", format="uri", nullable=true),
 *     @OA\Property(property="link", type="string", format="uri", nullable=true),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(
 *         property="ingredients",
 *         type="array",
 *         nullable=true,
 *
 *         @OA\Items(
 *
 *             @OA\Property(property="name", type="string"),
 *             @OA\Property(property="amount", type="number", nullable=true),
 *             @OA\Property(property="unit", type="string", nullable=true)
 *         )
 *     ),
 *     @OA\Property(property="steps", type="array", nullable=true, @OA\Items(type="string")),
 *     @OA\Property(property="type", type="string", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class LibraryRecipeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'rating' => $this->rating,
            'calories' => $this->calories,
            'time_to_make' => $this->time_to_make,
            'servings' => $this->servings,
            'cover' => $this->cover_path,
            'link' => $this->link,
            'description' => $this->description,
            'ingredients' => $this->ingredients ?? [],
            'steps' => $this->steps ?? [],
            'type' => $this->type,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
