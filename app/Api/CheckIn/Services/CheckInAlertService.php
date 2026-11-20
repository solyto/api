<?php

namespace App\Api\CheckIn\Services;

use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInAlert;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\CheckIn\Notifications\CheckInAlertNotification;
use Carbon\CarbonImmutable;

class CheckInAlertService
{
    // How late an alert may still go out, so a scheduler run that slips by a minute or two doesn't skip it.
    private const int WINDOW_MINUTES = 5;

    public function sendDue(): int
    {
        $sent = 0;

        CheckInAlert::query()
            ->whereHas('tracker', fn ($query) => $query->where('enabled', true))
            ->with(['tracker.user.settings', 'tracker.user.notificationSettings'])
            ->chunkById(500, function ($alerts) use (&$sent) {
                foreach ($alerts as $alert) {
                    // One user's failure (an unknown stored timezone, a mail or
                    // Telegram outage) must not cost everyone after them their reminder.
                    try {
                        $sent += $this->sendIfDue($alert) ? 1 : 0;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $sent;
    }

    private function sendIfDue(CheckInAlert $alert): bool
    {
        $tracker = $alert->tracker;
        $user = $tracker->user;
        $now = CarbonImmutable::now($user->settings->timezone ?? 'UTC');
        $today = $now->toDateString();
        $due = CarbonImmutable::parse($today.' '.substr((string) $alert->time, 0, 5), $now->getTimezone());

        if ($alert->last_sent_on?->toDateString() === $today || $now->lt($due) || $now->gte($due->addMinutes(self::WINDOW_MINUTES))) {
            return false;
        }

        $alert->update(['last_sent_on' => $today]);

        $value = CheckInValue::where('check_in_tracker_id', $tracker->id)->whereDate('date', $today)->value('value');
        $value = $value === null ? null : (int) $value;

        $isDone = $tracker->type === CheckInTrackerType::Count
            ? ($value ?? 0) >= $alert->target
            : $value !== null;

        if ($isDone) {
            return false;
        }

        $user->notify(new CheckInAlertNotification(
            date: $today,
            trackerKey: $tracker->key,
            trackerName: $tracker->name,
            current: $tracker->type === CheckInTrackerType::Count ? ($value ?? 0) : null,
            target: $tracker->type === CheckInTrackerType::Count ? $alert->target : null,
        ));

        return true;
    }
}
