<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->boolean('export_ready_ui')->default(true);
            $table->boolean('export_ready_email')->default(false);
            $table->boolean('export_ready_push')->default(true);
            $table->boolean('export_ready_telegram')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'export_ready_ui',
                'export_ready_email',
                'export_ready_push',
                'export_ready_telegram',
            ]);
        });
    }
};
