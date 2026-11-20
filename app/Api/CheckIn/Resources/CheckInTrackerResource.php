<?php

namespace App\Api\CheckIn\Resources;

use App\Api\CheckIn\Models\CheckInTracker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="CheckInTracker",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="key", type="string", nullable=true, description="Set for built-in trackers (e.g. mood); null for the user's own trackers."),
 *     @OA\Property(property="name", type="string", nullable=true, description="The user's own name; null for built-in trackers, which are named by the app."),
 *     @OA\Property(property="type", type="string", enum={"scale", "yes_no", "count", "sport"}),
 *     @OA\Property(property="icon", type="string", nullable=true),
 *     @OA\Property(property="position", type="integer"),
 *     @OA\Property(property="enabled", type="boolean"),
 *     @OA\Property(property="scored", type="boolean"),
 *     @OA\Property(property="goal", ref="#/components/schemas/CheckInGoal", nullable=true),
 *     @OA\Property(property="alerts", type="array", @OA\Items(ref="#/components/schemas/CheckInAlert"))
 * )
 *
 * @mixin CheckInTracker
 */
class CheckInTrackerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'type' => $this->type->value,
            'icon' => $this->icon,
            'position' => $this->position,
            'enabled' => $this->enabled,
            'scored' => $this->scored,
            'goal' => $this->whenLoaded('goal', fn () => $this->goal ? new CheckInGoalResource($this->goal) : null),
            'alerts' => CheckInAlertResource::collection($this->whenLoaded('alerts')),
        ];
    }
}
