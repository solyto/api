<?php

namespace App\Shared\Services;

use App\Api\Calendars\Services\CalendarService;
use App\Api\Dashboard\DTOs\DetectionResult;
use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\TimeTracking\Services\TimeTrackingService;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Services\TodoService;
use App\Api\Users\Models\User;
use App\Dav\DTOs\EventDTO;
use App\Shared\Exceptions\IntegrationAuthException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IntegrationGateway
{
    private ?User $user = null;

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function detect(string $content): DetectionResult
    {
        return app(QuickAddService::class)->detect($content);
    }

    /**
     * @throws IntegrationAuthException
     */
    public function commit(string $content, QuickAddContentType $contentType, ?array $metadata): mixed
    {
        $this->checkAuth();

        return app(QuickAddService::class)->commit($this->user, $content, $contentType, $metadata);
    }

    /**
     * @throws IntegrationAuthException
     */
    public function todos(): Collection
    {
        $this->checkAuth();

        return app(TodoService::class)->list($this->user);
    }

    /**
     * @throws IntegrationAuthException
     */
    public function dueTodos(): Collection
    {
        $this->checkAuth();

        return Todo::forUser($this->user->id)
            ->where('is_completed', false)
            ->where('due_at', '<=', today())
            ->get();
    }

    /**
     * @return Collection<int, EventDTO>
     * @throws IntegrationAuthException
     */
    public function todayAppointments(): Collection
    {
        $this->checkAuth();

        return collect(app(CalendarService::class)->listTodayEvents($this->user));
    }

    /**
     * Not completed and due today or earlier in the user's timezone, oldest first.
     *
     * @return Collection<int, Todo>
     * @throws IntegrationAuthException
     */
    public function dueTodosInUserTimezone(int $limit): Collection
    {
        $this->checkAuth();

        return Todo::forUser($this->user->id)
            ->where('is_completed', false)
            ->whereDate('due_at', '<=', today($this->user->settings->timezone ?? 'UTC')->toDateString())
            ->orderBy('due_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, EventDTO>
     * @throws IntegrationAuthException
     */
    public function widgetEvents(): Collection
    {
        $this->checkAuth();

        return collect(app(CalendarService::class)->listWidgetEvents($this->user));
    }

    /**
     * @throws IntegrationAuthException
     */
    public function completeTodo(Todo $todo): Todo
    {
        $this->checkAuth();

        if ($todo->is_completed) {
            return $todo;
        }

        return app(TodoService::class)->update($todo, ['is_completed' => true]);
    }

    /**
     * @throws IntegrationAuthException
     */
    public function runningTimeTrackingEntry(): ?TimeTrackingEntry
    {
        $this->checkAuth();

        return TimeTrackingEntry::forUser($this->user->id)
            ->whereNull('stopped_at')
            ->with('project')
            ->latest('started_at')
            ->first();
    }

    /**
     * Most recently used first (latest entry start), then never used projects by title.
     *
     * @return Collection<int, TimeTrackingProject>
     * @throws IntegrationAuthException
     */
    public function timeTrackingProjectsByLastUse(int $limit, ?string $exceptProjectId = null): Collection
    {
        $this->checkAuth();

        // MariaDB and SQLite both sort NULL last in descending order, so never used projects follow the used ones.
        return TimeTrackingProject::forUser($this->user->id)
            ->when($exceptProjectId, fn ($query) => $query->whereKeyNot($exceptProjectId))
            ->withMax('entries', 'started_at')
            ->orderByDesc('entries_max_started_at')
            ->orderBy('title')
            ->limit($limit)
            ->get();
    }

    /**
     * Starts the project's timer and stops any other running one; a no-op while the project's timer runs.
     *
     * @throws IntegrationAuthException
     */
    public function startTimeTracking(TimeTrackingProject $project): void
    {
        $this->checkAuth();

        $service = app(TimeTrackingService::class);

        DB::transaction(function () use ($project, $service) {
            $running = TimeTrackingEntry::forUser($this->user->id)
                ->whereNull('stopped_at')
                ->lockForUpdate()
                ->get();

            $running->reject(fn (TimeTrackingEntry $entry) => $entry->project_id === $project->id)
                ->each(fn (TimeTrackingEntry $entry) => $service->stopTimer($entry));

            if (!$running->contains('project_id', $project->id)) {
                $service->startTimer($this->user, ['project_id' => $project->id]);
            }
        }, 3);
    }

    /**
     * @throws IntegrationAuthException
     */
    public function stopTimeTracking(TimeTrackingEntry $entry): void
    {
        $this->checkAuth();

        if ($entry->stopped_at !== null) {
            return;
        }

        app(TimeTrackingService::class)->stopTimer($entry);
    }

    /**
     * @throws IntegrationAuthException
     */
    private function checkAuth(): void
    {
        if (!$this->user) {
            throw new IntegrationAuthException();
        }
    }
}
