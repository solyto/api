<?php

namespace App\Api\Alerts\Resources;

use App\Api\Alerts\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Alert",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="source", type="string", enum={"check_in_tracker", "goal"}),
 *     @OA\Property(property="check_in_tracker_id", type="integer", nullable=true, description="For goal alerts: the goal's tracker"),
 *     @OA\Property(property="goal_id", type="integer", nullable=true),
 *     @OA\Property(property="rule", type="string", enum={"average_below", "average_above", "no_entry_days", "entry_days", "goal_reached"}),
 *     @OA\Property(property="threshold", type="number", nullable=true),
 *     @OA\Property(property="days", type="integer", nullable=true),
 *     @OA\Property(property="enabled", type="boolean"),
 *     @OA\Property(property="suggestion", type="string", nullable=true),
 *     @OA\Property(property="last_triggered_on", type="string", format="date", nullable=true),
 *     @OA\Property(
 *         property="state",
 *         type="object",
 *         description="What the rule sees now, on completed days (up to yesterday).",
 *         @OA\Property(property="active", type="boolean"),
 *         @OA\Property(property="average", type="number", nullable=true),
 *         @OA\Property(property="streak", type="integer", nullable=true),
 *         @OA\Property(property="last_entry", type="string", format="date", nullable=true)
 *     )
 * )
 *
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    /** @param array<string, mixed> $state */
    public function __construct(Alert $alert, private readonly array $state)
    {
        parent::__construct($alert);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'check_in_tracker_id' => $this->check_in_tracker_id ?? $this->goal?->check_in_tracker_id,
            'goal_id' => $this->goal_id,
            'rule' => $this->rule->value,
            'threshold' => $this->threshold,
            'days' => $this->days,
            'enabled' => $this->enabled,
            'suggestion' => $this->suggestion,
            'last_triggered_on' => $this->last_triggered_on?->format('Y-m-d'),
            'state' => $this->state,
        ];
    }
}
