<?php

use App\Api\Notes\Models\NoteCategory;
use App\Api\Users\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        User::query()->select('id')->chunkById(500, function ($users) {
            foreach ($users as $user) {
                NoteCategory::provisionJournal($user);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Journal folders are user data; the backfill is not reversed.
    }
};
