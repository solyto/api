<?php

namespace App\Api\Goals\Resources;

use App\Api\Goals\Models\Goal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Goal",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="source", type="string", enum={"check_in_tracker"}),
 *     @OA\Property(property="check_in_tracker_id", type="integer", nullable=true),
 *     @OA\Property(property="period", type="string", enum={"day", "week", "month"}),
 *     @OA\Property(property="comparison", type="string", enum={"at_least", "at_most"}),
 *     @OA\Property(property="target", type="number"),
 *     @OA\Property(property="notify_when_reached", type="boolean"),
 *     @OA\Property(
 *         property="progress",
 *         type="object",
 *         description="The current period (up to and including today) and whether each of the previous periods was met, newest first.",
 *         @OA\Property(property="current", type="number", nullable=true, description="Days done, total or average, depending on the tracker; null for a 1–5 tracker without ratings yet."),
 *         @OA\Property(property="met", type="boolean"),
 *         @OA\Property(property="period_start", type="string", format="date"),
 *         @OA\Property(property="period_end", type="string", format="date"),
 *         @OA\Property(property="history", type="array", @OA\Items(type="object", @OA\Property(property="period_start", type="string", format="date"), @OA\Property(property="met", type="boolean")))
 *     )
 * )
 *
 * @mixin Goal
 */
class GoalResource extends JsonResource
{
    /** @param array<string, mixed> $progress */
    public function __construct(Goal $goal, private readonly array $progress)
    {
        parent::__construct($goal);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'check_in_tracker_id' => $this->check_in_tracker_id,
            'period' => $this->period->value,
            'comparison' => $this->comparison->value,
            'target' => $this->target,
            'notify_when_reached' => $this->reachedAlert !== null,
            'progress' => $this->progress,
        ];
    }
}
