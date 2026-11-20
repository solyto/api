<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->boolean('check_in_alert_ui')->default(true);
            $table->boolean('check_in_alert_email')->default(false);
            $table->boolean('check_in_alert_push')->default(true);
            $table->boolean('check_in_alert_telegram')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_alert_ui',
                'check_in_alert_email',
                'check_in_alert_push',
                'check_in_alert_telegram',
            ]);
        });
    }
};
