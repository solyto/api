<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rename tracker table to check_in
        Schema::rename('tracker', 'check_in');

        // Rename tracker_alert to check_in_alert in telegram_bot_connections
        Schema::table('telegram_bot_connections', function (Blueprint $table) {
            $table->renameColumn('tracker_alert', 'check_in_alert');
        });

        // Rename daily_tracker_reminder columns in user_notification_settings
        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->renameColumn('daily_tracker_reminder_ui', 'daily_check_in_reminder_ui');
            $table->renameColumn('daily_tracker_reminder_email', 'daily_check_in_reminder_email');
            $table->renameColumn('daily_tracker_reminder_push', 'daily_check_in_reminder_push');
            $table->renameColumn('daily_tracker_reminder_telegram', 'daily_check_in_reminder_telegram');
        });

        // Update widgets JSON in user_settings: replace 'tracker-stats' with 'check-in-stats'
        DB::statement("UPDATE user_settings SET widgets = REPLACE(widgets, '\"tracker-stats\"', '\"check-in-stats\"') WHERE widgets LIKE '%tracker-stats%'");

        // Update navigation JSON in user_settings: replace 'tracker' key with 'checkIn'
        DB::statement("UPDATE user_settings SET navigation = REPLACE(navigation, '\"tracker\":', '\"checkIn\":') WHERE navigation LIKE '%\"tracker\":%'");
    }

    public function down(): void
    {
        // Rename check_in table back to tracker
        Schema::rename('check_in', 'tracker');

        Schema::table('telegram_bot_connections', function (Blueprint $table) {
            $table->renameColumn('check_in_alert', 'tracker_alert');
        });

        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->renameColumn('daily_check_in_reminder_ui', 'daily_tracker_reminder_ui');
            $table->renameColumn('daily_check_in_reminder_email', 'daily_tracker_reminder_email');
            $table->renameColumn('daily_check_in_reminder_push', 'daily_tracker_reminder_push');
            $table->renameColumn('daily_check_in_reminder_telegram', 'daily_tracker_reminder_telegram');
        });

        // Revert widgets JSON
        DB::statement("UPDATE user_settings SET widgets = REPLACE(widgets, '\"check-in-stats\"', '\"tracker-stats\"') WHERE widgets LIKE '%check-in-stats%'");

        // Revert navigation JSON
        DB::statement("UPDATE user_settings SET navigation = REPLACE(navigation, '\"checkIn\":', '\"tracker\":') WHERE navigation LIKE '%\"checkIn\":%'");
    }
};
