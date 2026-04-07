<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Music release notifications
            $table->boolean('music_release_ui')->default(true);
            $table->boolean('music_release_email')->default(false);
            $table->boolean('music_release_push')->default(true);
            $table->boolean('music_release_telegram')->default(false);

            // Book release notifications
            $table->boolean('book_release_ui')->default(true);
            $table->boolean('book_release_email')->default(false);
            $table->boolean('book_release_push')->default(true);
            $table->boolean('book_release_telegram')->default(false);

            // Friend request notifications
            $table->boolean('friend_request_ui')->default(true);
            $table->boolean('friend_request_email')->default(false);
            $table->boolean('friend_request_push')->default(true);
            $table->boolean('friend_request_telegram')->default(false);

            // Daily day reminder (todos/calendar for the day)
            $table->boolean('daily_day_reminder_ui')->default(false);
            $table->boolean('daily_day_reminder_email')->default(false);
            $table->boolean('daily_day_reminder_push')->default(false);
            $table->boolean('daily_day_reminder_telegram')->default(false);

            // Daily tracker reminder
            $table->boolean('daily_tracker_reminder_ui')->default(false);
            $table->boolean('daily_tracker_reminder_email')->default(false);
            $table->boolean('daily_tracker_reminder_push')->default(false);
            $table->boolean('daily_tracker_reminder_telegram')->default(false);

            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_settings');
    }
};
