<?php

namespace App\Api\Feeds\Resources;

use App\Api\Feeds\DTOs\FeedImportStateDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="FeedImportState",
 *
 *     @OA\Property(property="stage", type="string", enum={"parsed", "running", "finished"}),
 *     @OA\Property(property="total", type="integer"),
 *     @OA\Property(property="processed", type="integer"),
 *     @OA\Property(
 *         property="feeds",
 *         type="array",
 *
 *         @OA\Items(
 *
 *             @OA\Property(property="url", type="string", format="uri"),
 *             @OA\Property(property="title", type="string"),
 *             @OA\Property(property="already_subscribed", type="boolean"),
 *             @OA\Property(property="status", type="string", nullable=true, enum={"pending", "subscribed", "already_subscribed", "unreachable", "not_a_feed"})
 *         )
 *     )
 * )
 *
 * @mixin FeedImportStateDTO
 */
class FeedImportStateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stage' => $this->stage->value,
            'total' => $this->total(),
            'processed' => $this->processed(),
            'feeds' => array_map(fn (array $feed) => [
                'url' => $feed['url'],
                'title' => $feed['title'],
                'already_subscribed' => $feed['already_subscribed'],
                'status' => $feed['status']?->value,
            ], $this->feeds),
        ];
    }
}
