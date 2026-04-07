<?php

namespace App\Api\Users\Models;

use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\Users\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasPushSubscriptions, HasUuids, Notifiable;

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'language',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->role === 'super_admin';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSettings::class);
    }

    public function notificationSettings(): HasOne
    {
        return $this->hasOne(UserNotificationSettings::class);
    }

    public function telegramConnection(): HasOne
    {
        return $this->hasOne(TelegramBotConnection::class);
    }

    public function friends()
    {
        return Friend::where('user_id_1', $this->id)
            ->orWhere('user_id_2', $this->id)
            ->with(['user1.profile', 'user2.profile'])
            ->get()
            ->map(function ($friend) {
                return $friend->user_id_1 === $this->id
                    ? $friend->user2
                    : $friend->user1;
            });
    }

    public function sentFriendRequests()
    {
        return $this->hasMany(FriendRequest::class, 'sender_id')
            ->with(['receiver.profile']);
    }

    public function receivedFriendRequests()
    {
        return $this->hasMany(FriendRequest::class, 'receiver_id')
            ->with(['sender.profile']);
    }

    public function allFriendRequests()
    {
        $sent = FriendRequest::where('sender_id', $this->id)
            ->with(['receiver.profile'])
            ->get()
            ->map(function ($request) {
                $request->other_user = $request->receiver;
                $request->direction = 'sent';

                return $request;
            });

        $received = FriendRequest::where('receiver_id', $this->id)
            ->with(['sender.profile'])
            ->get()
            ->map(function ($request) {
                $request->other_user = $request->sender;
                $request->direction = 'received';

                return $request;
            });

        return $sent->merge($received);
    }
}
