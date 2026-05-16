<?php

namespace App\Shared\Services;

use App\Api\Calendars\Models\CalendarEntry;
use App\Api\Dashboard\DTOs\DetectionResult;
use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Services\TodoService;
use App\Api\Users\Models\User;
use App\Shared\Exceptions\IntegrationAuthException;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class IntegrationGateway
{
    private ?User $user;

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
     * @throws IntegrationAuthException
     */
    public function todayAppointments(): Collection
    {
        $this->checkAuth();

        return CalendarEntry::forUser($this->user->id)
            ->whereBetween('start_date', [
                Carbon::today()->startOfDay()->timestamp,
                Carbon::today()->endOfDay()->timestamp,
            ])
            ->get();
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
