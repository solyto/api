<?php

namespace App\Api\Widgets\Services;

use App\Api\Calendars\Services\CalendarService;
use App\Api\CheckIn\Enums\CheckInBuiltInTracker;
use App\Api\CheckIn\Services\CheckInService;
use App\Api\CheckIn\Services\CheckInTrackerService;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\TimeTracking\Services\TimeTrackingService;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Services\TodoService;
use App\Api\Users\Models\User;
use App\Api\Widgets\DTOs\WidgetFormatDTO;
use App\Api\Widgets\DTOs\YourDayDTO;
use App\Dav\DTOs\EventDTO;
use Carbon\CarbonImmutable;

class WidgetService
{
    private const int SCHEMA_VERSION = 1;
    private const int SECTION_LIMIT = 20;
    private const array TIME_FORMATS = ['H:i', 'g:i A', 'g:i a'];
    private const string DEFAULT_TIME_FORMAT = 'H:i';
    private const string DEFAULT_DATE_FORMAT = 'dd.mm.YYYY';
    private const string COLOR_PATTERN = '/^#[0-9A-Fa-f]{6}$/';
    private const array CHECK_IN_SPORTS = [1 => 'dumbbell', 2 => 'bike', 3 => 'mountain', 4 => 'footprints', 5 => 'waves_ladder', 6 => 'yoga'];
    private const array CHECK_IN_COLORS = [1 => '#EF4444', 2 => '#F97316', 3 => '#3B82F6', 4 => '#86EFAC', 5 => '#22C55E'];
    private const string CHECK_IN_SPORTS_COLOR = '#1DBDA5';
    private const string CHECK_IN_MENSTRUATION_COLOR = '#FB7185';

    public function __construct(
        private readonly CalendarService $calendarService,
        private readonly TodoService $todoService,
        private readonly TimeTrackingService $timeTrackingService,
        private readonly CheckInService $checkInService,
        private readonly CheckInTrackerService $checkInTrackerService,
    ) {}

    public function yourDay(User $user): YourDayDTO
    {
        $format = $this->format($user);
        $timezone = $format->timezone;

        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $tomorrow = $today->addDay();

        $calendarListing = $this->calendarService->listWidgetEvents($user);
        $byStartAllDayFirst = fn (EventDTO $a, EventDTO $b) => [$this->localStart($a, $timezone), !$a->isAllDay]
            <=> [$this->localStart($b, $timezone), !$b->isAllDay];
        [$todayEvents, $upcomingEvents] = collect($calendarListing->events)
            ->filter(fn (EventDTO $event) => $event->startDate !== null)
            ->sort($byStartAllDayFirst)
            ->partition(fn (EventDTO $event) => $this->localStart($event, $timezone) < $tomorrow);

        $sections = [
            'today' => $todayEvents
                ->sortBy(fn (EventDTO $event) => !$event->isAllDay)
                ->map(fn (EventDTO $event) => $this->eventRow($event, $today, false, $format)),
            'due' => $this->todoService->due($user, $today->toDateString(), self::SECTION_LIMIT)
                ->map(fn (Todo $todo) => $this->todoRow($todo, $today, $format)),
            'upcoming' => $upcomingEvents->map(fn (EventDTO $event) => $this->eventRow($event, $today, true, $format)),
        ];

        $widget = [
            'kind' => 'your-day',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $now->toIso8601String(),
            'refresh_at' => $tomorrow->toIso8601String(),
            'labels' => [
                'title' => __('widgets.your_day_title', [], $format->locale),
                'empty' => __('widgets.your_day_empty', [], $format->locale),
            ],
            'sections' => $this->sections($sections, $format->locale),
        ];

        return new YourDayDTO($widget, $calendarListing->unavailableCalendars, $calendarListing->credentialsRejectedCalendars);
    }

    public function completeTodo(User $user, Todo $todo): void
    {
        if ($todo->is_completed) {
            return;
        }

        $this->todoService->update($todo, ['is_completed' => true]);
    }

    public function timeTracking(User $user): array
    {
        $format = $this->format($user);

        $running = $this->timeTrackingService->runningEntry($user);

        $sections = [
            'running' => collect($running ? [$this->runningRow($running, $format->timezone)] : []),
            'projects' => $this->timeTrackingService->projectsByLastUse($user, self::SECTION_LIMIT, $running?->project_id)
                ->map(fn (TimeTrackingProject $project) => $this->projectRow($project)),
        ];

        return [
            'kind' => 'time-tracking',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => CarbonImmutable::now($format->timezone)->toIso8601String(),
            'labels' => [
                'title' => __('widgets.time_tracking_title', [], $format->locale),
                'empty' => __('widgets.time_tracking_empty', [], $format->locale),
            ],
            'sections' => $this->sections($sections, $format->locale),
        ];
    }

    public function startTimeTracking(User $user, TimeTrackingProject $project): void
    {
        $this->timeTrackingService->switchTo($user, $project);
    }

    public function stopTimeTracking(User $user, TimeTrackingEntry $entry): void
    {
        if ($entry->stopped_at !== null) {
            return;
        }

        $this->timeTrackingService->stopTimer($entry);
    }

    public function checkIn(User $user): array
    {
        $format = $this->format($user);
        $sports = $user->settings->check_in_settings['selected_sports'] ?? CheckInBuiltInTracker::DEFAULT_SPORTS;

        $now = CarbonImmutable::now($format->timezone);
        $today = $now->startOfDay();
        $values = $this->checkInService->builtInValuesOn($user, $today->toDateString());

        $rows = collect($this->checkInTrackerService->enabledBuiltInKeys($user))
            ->map(fn (string $tracker) => $this->checkInRow($tracker, $values[$tracker] ?? null, $sports, $format->locale));

        return [
            'kind' => 'check-in',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $now->toIso8601String(),
            'refresh_at' => $today->addDay()->toIso8601String(),
            'labels' => [
                'title' => __('widgets.check_in_title', [], $format->locale),
                'empty' => __('widgets.check_in_empty', [], $format->locale),
            ],
            'sections' => $rows->isEmpty() ? [] : [['key' => 'today', 'rows' => $rows->values()->all()]],
        ];
    }

    public function setCheckIn(User $user, string $tracker, ?int $value): void
    {
        $this->checkInService->setBuiltInValue($user, CarbonImmutable::now($user->timezone())->toDateString(), $tracker, $value);
    }

    private function checkInRow(string $tracker, ?int $value, array $selectedSports, string $locale): array
    {
        return [
            'id' => $tracker,
            'title' => __('widgets.tracker_' . $tracker, [], $locale),
            'all_day' => true,
            'action' => [
                'type' => 'scale',
                'key' => 'check_in.set',
                'value' => $value,
                'options' => match ($tracker) {
                    'sports' => $this->sportOptions($selectedSports, $value),
                    default => $this->scaleOptions($tracker),
                },
            ],
        ];
    }

    private function scaleOptions(string $tracker): array
    {
        return array_map(fn (int $option) => [
            'value' => $option,
            'icon' => $tracker === 'mood' ? 'mood_' . $option : $tracker,
            'color' => $tracker === 'menstruation' ? self::CHECK_IN_MENSTRUATION_COLOR : self::CHECK_IN_COLORS[$option],
        ], range(1, 5));
    }

    // Like the app: the selected sports in order, plus today's stored sport when it is no longer selected.
    private function sportOptions(array $selectedSports, ?int $value): array
    {
        $ids = collect($selectedSports)
            ->map(fn ($sport) => array_search($sport, self::CHECK_IN_SPORTS, true))
            ->filter()
            ->unique()
            ->values();

        $isStoredSportUnselected = $value !== null && isset(self::CHECK_IN_SPORTS[$value]) && !$ids->contains($value);
        if ($isStoredSportUnselected) {
            $ids->push($value);
        }

        return $ids->map(fn (int $id) => [
            'value' => $id,
            'icon' => 'sport_' . self::CHECK_IN_SPORTS[$id],
            'color' => self::CHECK_IN_SPORTS_COLOR,
        ])->all();
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

    private function eventRow(EventDTO $event, CarbonImmutable $today, bool $withDate, WidgetFormatDTO $format): array
    {
        $start = $this->localStart($event, $format->timezone);
        $end = $event->endDate ? $this->localDate($event->endDate, $event->isAllDay, $format->timezone) : null;
        $occurrenceStart = $event->originalStartDate ?? $event->startDate;

        $timeLabel = match (true) {
            $withDate => $this->dateTimeLabel($start, $event->isAllDay, $format),
            $event->isAllDay => __('widgets.all_day', [], $format->locale),
            $start >= $today => $start->format($format->timeFormat),
            default => null,
        };

        return $this->withoutNulls([
            'id' => $event->uri . '#' . $occurrenceStart?->format('Ymd\THis'),
            'title' => $event->title,
            'time_label' => $timeLabel,
            'at' => $this->rowDate($start, $event->isAllDay),
            'until' => $end ? $this->rowDate($end, $event->isAllDay) : null,
            'all_day' => $event->isAllDay,
            'icon' => 'event',
        ]);
    }

    private function dateTimeLabel(CarbonImmutable $start, bool $isAllDay, WidgetFormatDTO $format): string
    {
        $time = $isAllDay ? '' : ' ' . $start->format($format->timeFormat);

        return trim($this->shortDate($start, $format->dateFormat) . $time);
    }

    // All-day rows carry the calendar date, timed rows the full timestamp.
    private function rowDate(CarbonImmutable $date, bool $isAllDay): string
    {
        return $isAllDay ? $date->toDateString() : $date->toIso8601String();
    }

    private function todoRow(Todo $todo, CarbonImmutable $today, WidgetFormatDTO $format): array
    {
        $due = CarbonImmutable::parse($todo->due_at->toDateString(), $today->getTimezone());
        $isOverdue = $due < $today;

        return $this->withoutNulls([
            'id' => $todo->id,
            'title' => $todo->title,
            'subtitle' => $isOverdue ? __('widgets.overdue', ['date' => $this->shortDate($due, $format->dateFormat)], $format->locale) : null,
            'at' => $due->toDateString(),
            'all_day' => true,
            'icon' => 'todo',
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

    private function format(User $user): WidgetFormatDTO
    {
        $settings = $user->settings;

        return new WidgetFormatDTO(
            timezone: $user->timezone(),
            locale: in_array($settings?->language, config('app.locales'), true) ? $settings->language : 'en',
            timeFormat: in_array($settings?->time_format, self::TIME_FORMATS, true) ? $settings->time_format : self::DEFAULT_TIME_FORMAT,
            dateFormat: $settings?->date_format ?: self::DEFAULT_DATE_FORMAT,
        );
    }

    // A section holds at most SECTION_LIMIT rows; empty sections are left out.
    private function sections(array $sections, string $locale): array
    {
        return collect($sections)
            ->filter(fn ($rows) => $rows->isNotEmpty())
            ->map(fn ($rows, string $key) => [
                'key' => $key,
                'title' => __('widgets.section_' . $key, [], $locale),
                'rows' => $rows->take(self::SECTION_LIMIT)->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function withoutNulls(array $row): array
    {
        return array_filter($row, fn ($value) => $value !== null);
    }
}
