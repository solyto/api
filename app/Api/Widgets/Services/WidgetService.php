<?php

namespace App\Api\Widgets\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\CheckIn\Resources\CheckInSettingsResource;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;
use App\Dav\DTOs\EventDTO;
use App\Shared\Services\IntegrationGateway;
use Carbon\CarbonImmutable;

class WidgetService
{
    private const int SCHEMA_VERSION = 1;
    private const int SECTION_LIMIT = 20;
    private const array TIME_FORMATS = ['H:i', 'g:i A', 'g:i a'];
    private const string DEFAULT_TIME_FORMAT = 'H:i';
    private const string DEFAULT_DATE_FORMAT = 'dd.mm.YYYY';
    private const string COLOR_PATTERN = '/^#[0-9A-Fa-f]{6}$/';
    public const array CHECK_IN_TRACKERS = ['mood', 'sleep', 'dreams', 'food_quality', 'food_amount', 'water', 'sports', 'work', 'menstruation', 'alcohol', 'smoking', 'social_life'];
    public const array CHECK_IN_SPORTS = [1 => 'dumbbell', 2 => 'bike', 3 => 'mountain', 4 => 'footprints', 5 => 'waves_ladder', 6 => 'yoga'];
    private const array CHECK_IN_COLORS = [1 => '#EF4444', 2 => '#F97316', 3 => '#3B82F6', 4 => '#86EFAC', 5 => '#22C55E'];
    private const string CHECK_IN_SPORTS_COLOR = '#1DBDA5';
    private const string CHECK_IN_MENSTRUATION_COLOR = '#FB7185';

    public function __construct(private readonly IntegrationGateway $gateway) {}

    public function yourDay(User $user): array
    {
        $this->gateway->setUser($user);

        $timezone = $user->settings->timezone ?? 'UTC';
        $locale = in_array($user->settings?->language, config('app.locales'), true) ? $user->settings->language : 'en';
        $timeFormat = in_array($user->settings?->time_format, self::TIME_FORMATS, true) ? $user->settings->time_format : self::DEFAULT_TIME_FORMAT;
        $dateFormat = $user->settings?->date_format ?: self::DEFAULT_DATE_FORMAT;

        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $tomorrow = $today->addDay();

        [$todayEvents, $upcomingEvents] = $this->gateway->widgetEvents()
            ->filter(fn (EventDTO $event) => $event->startDate !== null)
            ->sort(fn (EventDTO $a, EventDTO $b) => [$this->localStart($a, $timezone), !$a->isAllDay] <=> [$this->localStart($b, $timezone), !$b->isAllDay])
            ->partition(fn (EventDTO $event) => $this->localStart($event, $timezone) < $tomorrow);

        $sections = [
            'today' => $todayEvents->sortBy(fn (EventDTO $event) => !$event->isAllDay)->map(fn (EventDTO $event) => $this->eventRow($event, $timezone, $today, false, $timeFormat, $dateFormat, $locale)),
            'due' => $this->gateway->dueTodosInUserTimezone(self::SECTION_LIMIT)
                ->map(fn (Todo $todo) => $this->todoRow($todo, $today, $dateFormat, $locale)),
            'upcoming' => $upcomingEvents->map(fn (EventDTO $event) => $this->eventRow($event, $timezone, $today, true, $timeFormat, $dateFormat, $locale)),
        ];

        return [
            'kind' => 'your-day',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $now->toIso8601String(),
            'refresh_at' => $tomorrow->toIso8601String(),
            'labels' => [
                'title' => __('widgets.your_day_title', [], $locale),
                'empty' => __('widgets.your_day_empty', [], $locale),
            ],
            'sections' => collect($sections)
                ->filter(fn ($rows) => $rows->isNotEmpty())
                ->map(fn ($rows, string $key) => [
                    'key' => $key,
                    'title' => __('widgets.section_' . $key, [], $locale),
                    'rows' => $rows->take(self::SECTION_LIMIT)->values()->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function completeTodo(User $user, Todo $todo): void
    {
        $this->gateway->setUser($user);
        $this->gateway->completeTodo($todo);
    }

    public function timeTracking(User $user): array
    {
        $this->gateway->setUser($user);

        $timezone = $user->settings->timezone ?? 'UTC';
        $locale = in_array($user->settings?->language, config('app.locales'), true) ? $user->settings->language : 'en';

        $running = $this->gateway->runningTimeTrackingEntry();

        $sections = [
            'running' => collect($running ? [$this->runningRow($running, $timezone)] : []),
            'projects' => $this->gateway->timeTrackingProjectsByLastUse(self::SECTION_LIMIT, $running?->project_id)
                ->map(fn (TimeTrackingProject $project) => $this->projectRow($project)),
        ];

        return [
            'kind' => 'time-tracking',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => CarbonImmutable::now($timezone)->toIso8601String(),
            'labels' => [
                'title' => __('widgets.time_tracking_title', [], $locale),
                'empty' => __('widgets.time_tracking_empty', [], $locale),
            ],
            'sections' => collect($sections)
                ->filter(fn ($rows) => $rows->isNotEmpty())
                ->map(fn ($rows, string $key) => [
                    'key' => $key,
                    'title' => __('widgets.section_' . $key, [], $locale),
                    'rows' => $rows->values()->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function startTimeTracking(User $user, TimeTrackingProject $project): void
    {
        $this->gateway->setUser($user);
        $this->gateway->startTimeTracking($project);
    }

    public function stopTimeTracking(User $user, TimeTrackingEntry $entry): void
    {
        $this->gateway->setUser($user);
        $this->gateway->stopTimeTracking($entry);
    }

    public function checkIn(User $user): array
    {
        $this->gateway->setUser($user);

        $timezone = $user->settings->timezone ?? 'UTC';
        $locale = in_array($user->settings?->language, config('app.locales'), true) ? $user->settings->language : 'en';
        $settings = $user->settings->check_in_settings ?? [];
        $enabled = $settings['enabled_trackers'] ?? CheckInSettingsResource::DEFAULT_TRACKERS;
        $sports = $settings['selected_sports'] ?? CheckInSettingsResource::DEFAULT_SPORTS;

        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $checkIn = $this->gateway->checkInOn($today->toDateString());

        $rows = collect(self::CHECK_IN_TRACKERS)
            ->filter(fn (string $tracker) => in_array($tracker, $enabled, true))
            ->map(fn (string $tracker) => $this->checkInRow($tracker, $this->checkInValue($checkIn, $tracker), $sports, $locale));

        return [
            'kind' => 'check-in',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $now->toIso8601String(),
            'refresh_at' => $today->addDay()->toIso8601String(),
            'labels' => [
                'title' => __('widgets.check_in_title', [], $locale),
                'empty' => __('widgets.check_in_empty', [], $locale),
            ],
            'sections' => $rows->isEmpty() ? [] : [['key' => 'today', 'rows' => $rows->values()->all()]],
        ];
    }

    public function setCheckIn(User $user, string $tracker, ?int $value): void
    {
        $this->gateway->setUser($user);
        $this->gateway->setCheckIn(CarbonImmutable::now($user->settings->timezone ?? 'UTC')->toDateString(), $tracker, $value);
    }

    private function checkInRow(string $tracker, ?int $value, array $selectedSports, string $locale): array
    {
        return [
            'id' => $tracker,
            'title' => __('widgets.tracker_' . $tracker, [], $locale),
            'all_day' => true,
            'route' => ['key' => 'check_in'],
            'action' => [
                'type' => 'scale',
                'key' => 'check_in.set',
                'value' => $value,
                'options' => match ($tracker) {
                    'mood' => array_map(fn (int $option) => ['value' => $option, 'icon' => 'mood_' . $option, 'color' => self::CHECK_IN_COLORS[$option]], range(1, 5)),
                    'sports' => $this->sportOptions($selectedSports, $value),
                    default => array_map(fn (int $option) => [
                        'value' => $option,
                        'icon' => $tracker,
                        'color' => $tracker === 'menstruation' ? self::CHECK_IN_MENSTRUATION_COLOR : self::CHECK_IN_COLORS[$option],
                    ], range(1, 5)),
                },
            ],
        ];
    }

    // Like the app: the selected sports in order, plus today's stored sport when it is no longer selected.
    private function sportOptions(array $selectedSports, ?int $value): array
    {
        $ids = collect($selectedSports)
            ->map(fn ($sport) => array_search($sport, self::CHECK_IN_SPORTS, true))
            ->filter()
            ->unique()
            ->values();

        if ($value !== null && isset(self::CHECK_IN_SPORTS[$value]) && !$ids->contains($value)) {
            $ids->push($value);
        }

        return $ids->map(fn (int $id) => ['value' => $id, 'icon' => 'sport_' . self::CHECK_IN_SPORTS[$id], 'color' => self::CHECK_IN_SPORTS_COLOR])->all();
    }

    private function checkInValue(?CheckIn $checkIn, string $tracker): ?int
    {
        $value = $checkIn?->getAttribute($tracker);

        return is_numeric($value) ? (int) $value : null;
    }

    private function runningRow(TimeTrackingEntry $entry, string $timezone): array
    {
        return $this->withoutNulls([
            'id' => $entry->id,
            'title' => $entry->project->title,
            'subtitle' => $entry->description ?: null,
            'at' => CarbonImmutable::instance($entry->started_at)->setTimezone($timezone)->toIso8601String(),
            'live' => 'elapsed',
            'all_day' => false,
            'color' => $this->color($entry->project->color),
            'icon' => 'timer',
            'route' => ['key' => 'time_tracking_project', 'id' => $entry->project_id],
            'action' => ['type' => 'button', 'key' => 'time_tracking.stop', 'icon' => 'stop'],
        ]);
    }

    private function projectRow(TimeTrackingProject $project): array
    {
        return $this->withoutNulls([
            'id' => $project->id,
            'title' => $project->title,
            'all_day' => false,
            'color' => $this->color($project->color),
            'route' => ['key' => 'time_tracking_project', 'id' => $project->id],
            'action' => ['type' => 'button', 'key' => 'time_tracking.start', 'icon' => 'play'],
        ]);
    }

    private function color(?string $color): ?string
    {
        return $color !== null && preg_match(self::COLOR_PATTERN, $color) ? $color : null;
    }

    private function eventRow(EventDTO $event, string $timezone, CarbonImmutable $today, bool $withDate, string $timeFormat, string $dateFormat, string $locale): array
    {
        $start = $this->localStart($event, $timezone);
        $end = $event->endDate ? $this->localDate($event->endDate, $event->isAllDay, $timezone) : null;

        $timeLabel = match (true) {
            $withDate => trim($this->shortDate($start, $dateFormat) . ($event->isAllDay ? '' : ' ' . $start->format($timeFormat))),
            $event->isAllDay => __('widgets.all_day', [], $locale),
            $start >= $today => $start->format($timeFormat),
            default => null,
        };

        return $this->withoutNulls([
            'id' => $event->uri . '#' . ($event->originalStartDate ?? $event->startDate)?->format('Ymd\THis'),
            'title' => $event->title,
            'time_label' => $timeLabel,
            'at' => $event->isAllDay ? $start->toDateString() : $start->toIso8601String(),
            'until' => $end ? ($event->isAllDay ? $end->toDateString() : $end->toIso8601String()) : null,
            'all_day' => $event->isAllDay,
            'icon' => 'event',
            'route' => ['key' => 'calendar'],
        ]);
    }

    private function todoRow(Todo $todo, CarbonImmutable $today, string $dateFormat, string $locale): array
    {
        $due = CarbonImmutable::parse($todo->due_at->toDateString(), $today->getTimezone());

        return $this->withoutNulls([
            'id' => $todo->id,
            'title' => $todo->title,
            'subtitle' => $due < $today ? __('widgets.overdue', ['date' => $this->shortDate($due, $dateFormat)], $locale) : null,
            'at' => $due->toDateString(),
            'all_day' => true,
            'icon' => 'todo',
            'route' => ['key' => 'todo', 'id' => $todo->id],
            'action' => ['type' => 'check', 'key' => 'todo.complete', 'checked' => false],
        ]);
    }

    private function localStart(EventDTO $event, string $timezone): CarbonImmutable
    {
        return $this->localDate($event->startDate, $event->isAllDay, $timezone);
    }

    // All-day dates are floating: keep the calendar date instead of converting it to the user's timezone.
    private function localDate(\DateTimeInterface $date, bool $isAllDay, string $timezone): CarbonImmutable
    {
        return $isAllDay
            ? CarbonImmutable::parse($date->format('Y-m-d'), $timezone)
            : CarbonImmutable::instance($date)->setTimezone($timezone);
    }

    // Mirrors the app's formatDateWithoutYear(): the user's dd/mm/YYYY pattern without the year.
    private function shortDate(CarbonImmutable $date, string $dateFormat): string
    {
        $formatted = str_replace(['dd', 'mm', 'YYYY', 'YY'], [$date->format('d'), $date->format('m'), '', ''], $dateFormat);

        return trim(str_replace('  ', ' ', $formatted), " /.-");
    }

    private function withoutNulls(array $row): array
    {
        return array_filter($row, fn ($value) => $value !== null);
    }
}
