<?php

namespace App\Api\Users\Services;

use App\Api\Users\Models\User;
use App\Api\Users\Models\UserSettings;

class UserSettingsService
{
    public function updateNavigation(User $user, array $navigation): void
    {
        $user->settings()->update(['navigation' => $navigation]);
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

    public function getCheckInSettings(User $user): UserSettings
    {
        return UserSettings::firstOrCreate(['user_id' => $user->id], []);
    }

    public function updateCheckInSettings(User $user, array $data): UserSettings
    {
        return UserSettings::updateOrCreate(
            ['user_id' => $user->id],
            ['check_in_settings' => $data]
        );
    }
}
