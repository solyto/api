<?php

namespace App\Api\CheckIn\Resources;

use App\Api\CheckIn\Models\CheckInGoal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="CheckInGoal",
 *
 *     @OA\Property(property="period", type="string", enum={"day", "week", "month"}),
 *     @OA\Property(property="comparison", type="string", enum={"at_least", "at_most"}),
 *     @OA\Property(property="target", type="number")
 * )
 *
 * @mixin CheckInGoal
 */
class CheckInGoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'period' => $this->period->value,
            'comparison' => $this->comparison->value,
            'target' => $this->target,
        ];
    }
}
