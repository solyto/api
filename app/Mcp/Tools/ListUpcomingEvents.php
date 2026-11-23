<?php

namespace App\Mcp\Tools;

use App\Api\Calendars\Resources\EventResource;
use App\Api\Calendars\Services\CalendarService;
use App\Dav\DTOs\EventDTO;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the user\'s calendar events from today on, across all their calendars. Times are in the user\'s timezone.')]
class ListUpcomingEvents extends SolytoTool
{
    private const int DEFAULT_DAYS = 7;
    private const int MAX_DAYS = 31;

    public function __construct(private readonly CalendarService $calendars) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_DAYS]]);
        $days = (int) ($validated['days'] ?? self::DEFAULT_DAYS);
        $user = $this->user($request);

        $from = Carbon::today($user->timezone());
        $until = $from->copy()->addDays($days);
        $isInWindow = fn (EventDTO $event) => $event->startDate < $until && ($event->endDate ?? $event->startDate) >= $from;

        // listEvents covers the month three months either side, so the current month's listing spans the whole window.
        $events = collect($this->calendars->listEvents($user, $from->format('Y-m'))->events)
            ->filter($isInWindow)
            ->sortBy(fn (EventDTO $event) => $event->startDate)
            ->values();

        return Response::json(EventResource::collection($events)->resolve(request()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->min(1)->max(self::MAX_DAYS)->default(self::DEFAULT_DAYS)
                ->description('How many days ahead to look, starting today.'),
        ];
    }
}
