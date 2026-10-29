<?php

namespace App\Shared\Services;

use App\Api\Calendars\Services\CalendarService;
use App\Api\Dashboard\DTOs\DetectionResult;
use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Services\TodoService;
use App\Api\Users\Models\User;
use App\Dav\DTOs\EventDTO;
use App\Shared\Exceptions\IntegrationAuthException;
use Illuminate\Support\Collection;

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
    private function checkAuth(): void
    {
        if (!$this->user) {
            throw new IntegrationAuthException();
        }
    }
}
