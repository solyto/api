<?php

namespace App\Api\Calendars\Resources;

use App\Dav\DTOs\CalendarDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="Calendar",
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="color", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="timezone", type="string", nullable=true),
 *     @OA\Property(property="order", type="integer"),
 *     @OA\Property(property="is_shared", type="boolean"),
 *     @OA\Property(property="share_owner", type="string", nullable=true),
 *     @OA\Property(property="invite_status", type="string", nullable=true),
 *     @OA\Property(property="share_token", type="string", nullable=true),
 *     @OA\Property(property="is_external", type="boolean", description="Connected from the user's own CalDAV server; its events are read and written there"),
 *     @OA\Property(property="is_unavailable", type="boolean", description="External calendar whose server could not be reached by the last event listing"),
 *     @OA\Property(property="is_credentials_rejected", type="boolean", description="Unavailable because its server rejected the account's username or app password; is_unavailable is true as well"),
 *     @OA\Property(property="is_read_only", type="boolean", description="Shared with the user read-only; its events cannot be created, changed or deleted")
 * )
 *
 * @mixin CalendarDTO
 */
class CalendarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->instanceId,
            'name' => $this->displayName,
            'color' => $this->color,
            'description' => $this->description,
            'timezone' => $this->timezone,
            'order' => $this->order,
            'is_shared' => $this->isShared,
            'share_owner' => $this->shareOwner,
            'invite_status' => $this->inviteStatus,
            'share_token' => $this->shareToken,
            'is_external' => $this->isExternal,
            'is_unavailable' => $this->isUnavailable,
            'is_credentials_rejected' => $this->isCredentialsRejected,
            'is_read_only' => $this->isReadOnly,
        ];
    }
}
