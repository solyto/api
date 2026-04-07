<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_tracking_entries', function (Blueprint $table) {
            $table->boolean('has_exact_times')->default(true)->after('duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('time_tracking_entries', function (Blueprint $table) {
            $table->dropColumn('has_exact_times');
        });
    }
};
