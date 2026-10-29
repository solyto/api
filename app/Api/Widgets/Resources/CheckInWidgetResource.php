<?php

namespace App\Api\Widgets\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="CheckInWidget",
 *     description="Generic widget format (see YourDayWidget) with the scale action: one row per enabled tracker of today's check-in. A scale action is posted with a JSON body {value: int|null}.",
 *
 *     @OA\Property(property="kind", type="string", example="check-in"),
 *     @OA\Property(property="schema_version", type="integer", example=1),
 *     @OA\Property(property="generated_at", type="string", format="date-time"),
 *     @OA\Property(property="refresh_at", type="string", format="date-time", description="The next local midnight in the user's timezone, when the widget resets to the new day"),
 *     @OA\Property(property="labels", type="object",
 *         @OA\Property(property="title", type="string"),
 *         @OA\Property(property="empty", type="string", description="Shown when there are no sections (no tracker enabled)")
 *     ),
 *     @OA\Property(property="sections", type="array", description="One today section, left out when no tracker is enabled",
 *         @OA\Items(type="object",
 *             @OA\Property(property="key", type="string", enum={"today"}),
 *             @OA\Property(property="rows", type="array", description="The enabled trackers in the app's order",
 *                 @OA\Items(type="object", required={"id","title","all_day","route","action"},
 *                     @OA\Property(property="id", type="string", description="Tracker key", enum={"mood","sleep","dreams","food_quality","food_amount","water","sports","work","menstruation","alcohol","smoking","social_life"}),
 *                     @OA\Property(property="title", type="string", description="Translated tracker name"),
 *                     @OA\Property(property="all_day", type="boolean", example=true),
 *                     @OA\Property(property="route", type="object", required={"key"},
 *                         @OA\Property(property="key", type="string", enum={"check_in"})
 *                     ),
 *                     @OA\Property(property="action", type="object", required={"type","key","value","options"},
 *                         @OA\Property(property="type", type="string", enum={"scale"}),
 *                         @OA\Property(property="key", type="string", enum={"check_in.set"}),
 *                         @OA\Property(property="value", type="integer", nullable=true, description="Today's value, null when not set"),
 *                         @OA\Property(property="options", type="array", description="The tappable values: 1-5 (mood icons mood_1 to mood_5, other trackers their own key as icon); sports are the selected sports as ids 1-6 with icons sport_<key>, plus today's sport when no longer selected",
 *                             @OA\Items(type="object", required={"value","icon","color"},
 *                                 @OA\Property(property="value", type="integer", example=3),
 *                                 @OA\Property(property="icon", type="string", example="mood_3"),
 *                                 @OA\Property(property="color", type="string", pattern="^#[0-9A-F]{6}$", example="#3B82F6")
 *                             )
 *                         )
 *                     )
 *                 )
 *             )
 *         )
 *     )
 * )
 */
class CheckInWidgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'kind' => $this['kind'],
            'schema_version' => $this['schema_version'],
            'generated_at' => $this['generated_at'],
            'refresh_at' => $this['refresh_at'],
            'labels' => $this['labels'],
            'sections' => $this['sections'],
        ];
    }
}
