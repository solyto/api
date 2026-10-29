<?php

namespace App\Api\Widgets\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="YourDayWidget",
 *     description="Generic widget format. Additive changes keep schema_version; clients ignore unknown fields and values.",
 *
 *     @OA\Property(property="kind", type="string", example="your-day"),
 *     @OA\Property(property="schema_version", type="integer", example=1),
 *     @OA\Property(property="generated_at", type="string", format="date-time"),
 *     @OA\Property(property="refresh_at", type="string", format="date-time", description="Optional; here the next local midnight in the user's timezone"),
 *     @OA\Property(property="labels", type="object",
 *         @OA\Property(property="title", type="string"),
 *         @OA\Property(property="empty", type="string", description="Shown when there are no sections")
 *     ),
 *     @OA\Property(property="sections", type="array", description="Empty sections are left out; at most 20 rows each",
 *         @OA\Items(type="object",
 *             @OA\Property(property="key", type="string", enum={"today","due","upcoming"}),
 *             @OA\Property(property="title", type="string"),
 *             @OA\Property(property="rows", type="array",
 *                 @OA\Items(type="object", required={"id","title","all_day","route"},
 *                     @OA\Property(property="id", type="string"),
 *                     @OA\Property(property="title", type="string"),
 *                     @OA\Property(property="subtitle", type="string"),
 *                     @OA\Property(property="time_label", type="string"),
 *                     @OA\Property(property="at", type="string", description="ISO timestamp, or Y-m-d date when all_day"),
 *                     @OA\Property(property="until", type="string", description="ISO timestamp, or Y-m-d date when all_day"),
 *                     @OA\Property(property="all_day", type="boolean"),
 *                     @OA\Property(property="icon", type="string", enum={"event","todo"}),
 *                     @OA\Property(property="route", type="object", required={"key"},
 *                         @OA\Property(property="key", type="string", enum={"todo","calendar"}),
 *                         @OA\Property(property="id", type="string")
 *                     ),
 *                     @OA\Property(property="action", type="object",
 *                         @OA\Property(property="type", type="string", enum={"check"}),
 *                         @OA\Property(property="key", type="string", enum={"todo.complete"}),
 *                         @OA\Property(property="checked", type="boolean")
 *                     )
 *                 )
 *             )
 *         )
 *     )
 * )
 */
class YourDayResource extends JsonResource
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
