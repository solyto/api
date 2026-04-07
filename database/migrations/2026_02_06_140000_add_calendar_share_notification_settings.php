<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->boolean('calendar_share_ui')->default(true);
            $table->boolean('calendar_share_email')->default(false);
            $table->boolean('calendar_share_push')->default(true);
            $table->boolean('calendar_share_telegram')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'calendar_share_ui',
                'calendar_share_email',
                'calendar_share_push',
                'calendar_share_telegram',
            ]);
        });
    }
};
