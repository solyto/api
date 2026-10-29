<?php

namespace App\Api\Widgets\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="TimeTrackingWidget",
 *     description="Generic widget format (see YourDayWidget) with the time tracking additions: row live and color, button actions. refresh_at is optional and left out here.",
 *
 *     @OA\Property(property="kind", type="string", example="time-tracking"),
 *     @OA\Property(property="schema_version", type="integer", example=1),
 *     @OA\Property(property="generated_at", type="string", format="date-time"),
 *     @OA\Property(property="labels", type="object",
 *         @OA\Property(property="title", type="string"),
 *         @OA\Property(property="empty", type="string", description="Shown when there are no sections")
 *     ),
 *     @OA\Property(property="sections", type="array", description="Empty sections are left out; running has at most one row, projects at most 20 (most recently used first, then by title, without the running project)",
 *         @OA\Items(type="object",
 *             @OA\Property(property="key", type="string", enum={"running","projects"}),
 *             @OA\Property(property="title", type="string"),
 *             @OA\Property(property="rows", type="array",
 *                 @OA\Items(type="object", required={"id","title","all_day","route"},
 *                     @OA\Property(property="id", type="string", format="uuid", description="Entry ID in running, project ID in projects"),
 *                     @OA\Property(property="title", type="string", description="Project title"),
 *                     @OA\Property(property="subtitle", type="string", description="Entry description, only when set"),
 *                     @OA\Property(property="at", type="string", format="date-time", description="Start of the running entry"),
 *                     @OA\Property(property="live", type="string", enum={"elapsed"}, description="at is a start time; the widget counts the elapsed time up"),
 *                     @OA\Property(property="all_day", type="boolean", example=false),
 *                     @OA\Property(property="color", type="string", pattern="^#[0-9A-Fa-f]{6}$", description="Only when the project has a valid #RRGGBB color"),
 *                     @OA\Property(property="icon", type="string", enum={"timer"}),
 *                     @OA\Property(property="route", type="object", required={"key","id"},
 *                         @OA\Property(property="key", type="string", enum={"time_tracking_project"}),
 *                         @OA\Property(property="id", type="string", format="uuid")
 *                     ),
 *                     @OA\Property(property="action", type="object", required={"type","key","icon"},
 *                         @OA\Property(property="type", type="string", enum={"button"}),
 *                         @OA\Property(property="key", type="string", enum={"time_tracking.start","time_tracking.stop"}),
 *                         @OA\Property(property="icon", type="string", enum={"play","stop"})
 *                     )
 *                 )
 *             )
 *         )
 *     )
 * )
 */
class TimeTrackingWidgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'kind' => $this['kind'],
            'schema_version' => $this['schema_version'],
            'generated_at' => $this['generated_at'],
            'labels' => $this['labels'],
            'sections' => $this['sections'],
        ];
    }
}
