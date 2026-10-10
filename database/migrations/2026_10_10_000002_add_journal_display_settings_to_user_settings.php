<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('journal_enabled')->default(false)->after('check_in_settings');
            $table->boolean('journal_calendar_mark')->default(false)->after('journal_enabled');
            $table->boolean('check_in_calendar_mark')->default(false)->after('journal_calendar_mark');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn(['journal_enabled', 'journal_calendar_mark', 'check_in_calendar_mark']);
        });
    }
};
