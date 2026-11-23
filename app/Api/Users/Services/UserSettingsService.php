<?php

namespace App\Api\Users\Services;

use App\Api\CheckIn\Enums\CheckInBuiltInTracker;
use App\Api\CheckIn\Services\CheckInTrackerService;
use App\Api\Users\Models\User;
use App\Api\Users\Models\UserSettings;

class UserSettingsService
{
    // Clients from this version on store navigation as {visible, desktop, mobile};
    // older ones read and write only a flat map of feature flags.
    public const string NESTED_NAVIGATION_SINCE = '1.1.0';

    // The navigation features of 1.0.15, the last release before the nested navigation.
    public const array LEGACY_NAVIGATION_FEATURES = [
        'calendar', 'todos', 'notes', 'libraries', 'contacts', 'checkIn', 'finances',
        'clipboard', 'dev_requests', 'feeds', 'timeTracking',
    ];

    public function __construct(private readonly CheckInTrackerService $checkInTrackers) {}

    public function updateNavigation(User $user, array $navigation): void
    {
        // A flat write from an older client replaces the visibility but keeps the stored orders.
        if (!array_key_exists('visible', $navigation)) {
            $stored = json_decode($user->settings->navigation ?? 'null', true);
            if (is_array($stored) && is_array($stored['visible'] ?? null)) {
                // Features the older client doesn't know keep their stored visibility.
                $unknown = array_diff_key($stored['visible'], array_flip(self::LEGACY_NAVIGATION_FEATURES));
                $navigation = [...$stored, 'visible' => [...$unknown, ...$navigation]];
            }
        }

        $user->settings()->update(['navigation' => $navigation]);
    }

    // What clients older than NESTED_NAVIGATION_SINCE understand: only the flat visibility.
    public static function legacyNavigation(?string $navigation): ?string
    {
        $decoded = json_decode($navigation ?? 'null', true);
        if (!is_array($decoded) || !is_array($decoded['visible'] ?? null)) {
            return $navigation;
        }

        // 1.0.x renders unknown keys as unnamed toggles, so only send the features it has.
        return json_encode((object) array_intersect_key($decoded['visible'], array_flip(self::LEGACY_NAVIGATION_FEATURES)));
    }

    public function updateLanguage(User $user, string $language): void
    {
        $user->settings()->update(['language' => $language]);
    }

    public function updateTimezone(User $user, string $timezone): void
    {
        $user->settings()->update(['timezone' => $timezone]);
    }

    public function updateDateFormat(User $user, string $dateFormat): void
    {
        $user->settings()->update(['date_format' => $dateFormat]);
    }

    public function updateTimeFormat(User $user, string $timeFormat): void
    {
        $user->settings()->update(['time_format' => $timeFormat]);
    }

    public function updateWeekStart(User $user, string $weekStart): void
    {
        $user->settings()->update(['week_start' => $weekStart]);
    }

    public function updateWeatherCity(User $user, array $data): void
    {
        $user->settings()->update([
            'weather_city' => $data['city'],
            'weather_latitude' => $data['latitude'],
            'weather_longitude' => $data['longitude'],
        ]);
    }

    public function updateWeatherTemperatureUnit(User $user, array $data): void
    {
        $user->settings()->update([
            'temperature_unit' => $data['temperature_unit'] ?? 'c',
        ]);
    }

    public function completeOnboarding(User $user): void
    {
        $user->settings()->update(['first_visit' => false]);
    }

    public function updateJournal(User $user, bool $enabled): void
    {
        $user->settings()->update(['journal_enabled' => $enabled]);
    }

    public function updateJournalCalendarMark(User $user, bool $enabled): void
    {
        $user->settings()->update(['journal_calendar_mark' => $enabled]);
    }

    public function updateCheckInCalendarMark(User $user, bool $enabled): void
    {
        $user->settings()->update(['check_in_calendar_mark' => $enabled]);
    }

    // The settings shape apps before the tracker tables read: on/off of the built-in trackers and the chosen sports.
    /**
     * @return array{enabled_trackers: array<int, string>, selected_sports: array<int, string>}
     */
    public function getCheckInSettings(User $user): array
    {
        $settings = UserSettings::firstOrCreate(['user_id' => $user->id], []);

        return [
            'enabled_trackers' => $this->checkInTrackers->enabledBuiltInKeys($user),
            'selected_sports' => $settings->check_in_settings['selected_sports'] ?? CheckInBuiltInTracker::DEFAULT_SPORTS,
        ];
    }

    /**
     * @return array{enabled_trackers: array<int, string>, selected_sports: array<int, string>}
     */
    public function updateCheckInSettings(User $user, array $data): array
    {
        $settings = UserSettings::firstOrCreate(['user_id' => $user->id], []);
        $settings->update(['check_in_settings' => [
            ...($settings->check_in_settings ?? []),
            'enabled_trackers' => $data['enabled_trackers'],
            'selected_sports' => $data['selected_sports'],
        ]]);

        $this->checkInTrackers->setEnabledBuiltIns($user, $data['enabled_trackers']);

        return $this->getCheckInSettings($user);
    }
}
