<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->boolean('dev_request_comment_ui')->default(true);
            $table->boolean('dev_request_comment_email')->default(false);
            $table->boolean('dev_request_comment_push')->default(true);
            $table->boolean('dev_request_comment_telegram')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'dev_request_comment_ui',
                'dev_request_comment_email',
                'dev_request_comment_push',
                'dev_request_comment_telegram',
            ]);
        });
    }
};
