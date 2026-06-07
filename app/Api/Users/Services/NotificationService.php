<?php

namespace App\Api\Users\Services;

use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;

class NotificationService
{
    public function listUnread(User $user): Collection
    {
        return $user->unreadNotifications;
    }

    public function markRead(User $user, string $notificationId): void
    {
        $notification = $user->notifications()->findOrFail($notificationId);
        $notification->markAsRead();
    }

    public function markAllRead(User $user): void
    {
        $user->unreadNotifications->markAsRead();
    }
}
