<?php

use App\Api\Users\Models\User;
use App\Api\Users\Models\UserProfile;
use App\Api\Users\Models\UserSettings;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up()
    {
        $users = User::all();

        foreach ($users as $user) {
            if (!$user->profile) {
                UserProfile::create([
                    'user_id' => $user->id,
                ]);
            }

            if (!$user->settings) {
                UserSettings::create([
                    'user_id' => $user->id,
                ]);
            }
        }
    }

    public function down()
    {
    }
};
