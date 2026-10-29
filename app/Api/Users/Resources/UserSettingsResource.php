<?php

namespace App\Api\Users\Resources;

use App\Api\Users\Models\UserSettings;
use App\Api\Users\Services\UserSettingsService;
use App\Shared\Helpers\ClientVersionHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="UserSettings",
 *
 *     @OA\Property(property="language", type="string", enum={"en","de","fr","es","it"}),
 *     @OA\Property(property="timezone", type="string"),
 *     @OA\Property(property="date_format", type="string"),
 *     @OA\Property(property="time_format", type="string"),
 *     @OA\Property(property="week_start", type="string", enum={"monday","sunday"}),
 *     @OA\Property(property="navigation", type="string", nullable=true, description="Client-owned JSON string as sent to PUT /users/me/settings/navigation (current {visible, desktop, mobile} or legacy flat format); null orders mean the client default. Clients below 1.1.0 (by X-Client-Version, none counts as below) get only the flat visibility."),
 *     @OA\Property(property="first_visit", type="boolean"),
 *     @OA\Property(property="journal_enabled", type="boolean"),
 *     @OA\Property(property="journal_calendar_mark", type="boolean"),
 *     @OA\Property(property="check_in_calendar_mark", type="boolean"),
 *     @OA\Property(property="temperature_unit", type="string", enum={"c","f"})
 * )
 *
 * @mixin UserSettings
 */
class UserSettingsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'language' => $this->language,
            'timezone' => $this->timezone,
            'date_format' => $this->date_format,
            'time_format' => $this->time_format,
            'week_start' => $this->week_start,
            'navigation' => $this->navigationFor($request),
            'first_visit' => $this->first_visit,
            'journal_enabled' => $this->journal_enabled,
            'journal_calendar_mark' => $this->journal_calendar_mark,
            'check_in_calendar_mark' => $this->check_in_calendar_mark,
            'temperature_unit' => $this->temperature_unit ?? 'c',
        ];
    }

    private function navigationFor(Request $request): ?string
    {
        $navigation = $this->navigation;

        return ClientVersionHelper::isAtLeast($request, UserSettingsService::NESTED_NAVIGATION_SINCE)
            ? $navigation
            : UserSettingsService::legacyNavigation($navigation);
    }
}
