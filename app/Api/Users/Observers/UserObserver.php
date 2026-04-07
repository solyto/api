<?php

namespace App\Api\Users\Observers;

use App\Api\Users\Models\User;
use App\Api\Users\Models\UserProfile;
use App\Api\Users\Models\UserSettings;
use App\Dav\Services\DavService;

class UserObserver
{
    public function created(User $user)
    {
        $this->createProfile($user);
        $this->createSettings($user);
        $this->createDav($user);
    }

    private function createProfile(User $user): void
    {
        UserProfile::create([
            'user_id' => $user->id
        ]);
    }

    private function createSettings(User $user): void
    {
        UserSettings::create([
            'user_id' => $user->id,
        ]);
    }

    private function createDav(User $user): void
    {
        $dav = app(DavService::class);
        $dav->calendars()->createDefaultCalendar($user);
        $dav->addressBooks()->createDefaultAddressBook($user);
    }
}
