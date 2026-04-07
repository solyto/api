<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nextcloud_calendar_entries', function (Blueprint $table) {
            $table->boolean('is_recurring')->default(false)->after('is_all_day');
            $table->text('recurrence_rule')->nullable()->after('is_recurring');
            $table->timestamp('recurrence_end')->nullable()->after('recurrence_rule');
        });
    }

    public function down(): void
    {
        Schema::table('nextcloud_calendar_entries', function (Blueprint $table) {
            $table->dropColumn(['is_recurring', 'recurrence_rule', 'recurrence_end']);
        });
    }
};
