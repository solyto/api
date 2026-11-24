<?php

namespace App\Api\Users\Services;

use App\Api\Users\Models\Friend;
use App\Api\Users\Models\FriendRequest;
use App\Api\Users\Models\User;
use App\Api\Users\Notifications\FriendRequestNotification;

class FriendService
{
    public function listFriends(User $user): \Illuminate\Support\Collection
    {
        return Friend::where('user_id_1', $user->id)
            ->orWhere('user_id_2', $user->id)
            ->with(['user1.profile', 'user2.profile'])
            ->get()
            ->map(function ($friend) use ($user) {
                return $friend->user_id_1 === $user->id
                    ? $friend->user2
                    : $friend->user1;
            });
    }

    public function listFriendRequests(User $user): \Illuminate\Support\Collection
    {
        $sent = FriendRequest::where('sender_id', $user->id)
            ->with(['receiver.profile'])
            ->get();

        $received = FriendRequest::where('receiver_id', $user->id)
            ->with(['sender.profile'])
            ->get();

        return $sent->merge($received);
    }

    public function sendFriendRequest(User $sender, array $data): FriendRequest
    {
        $data['sender_id'] = $sender->id;
        $friendRequest = FriendRequest::create($data);

        $receiver = User::find($data['receiver_id']);

        if ($receiver) {
            $receiver->notify(new FriendRequestNotification(name: $sender->name));
        }

        return $friendRequest;
    }

    public function acceptFriendRequest(FriendRequest $friendRequest): Friend
    {
        $friendRequest->update(['status' => 'accepted']);

        return Friend::create([
            'user_id_1' => $friendRequest->sender_id,
            'user_id_2' => $friendRequest->receiver_id,
            'friends_since' => now(),
        ]);
    }

    public function rejectFriendRequest(FriendRequest $friendRequest): void
    {
        $friendRequest->update(['status' => 'rejected']);
    }
}
