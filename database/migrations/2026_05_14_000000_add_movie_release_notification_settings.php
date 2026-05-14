<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->boolean('movie_release_ui')->default(true);
            $table->boolean('movie_release_email')->default(false);
            $table->boolean('movie_release_push')->default(true);
            $table->boolean('movie_release_telegram')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'movie_release_ui',
                'movie_release_email',
                'movie_release_push',
                'movie_release_telegram',
            ]);
        });
    }
};
