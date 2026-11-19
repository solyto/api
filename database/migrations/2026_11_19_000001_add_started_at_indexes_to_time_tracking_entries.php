<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_tracking_entries', function (Blueprint $table) {
            $table->index(['user_id', 'started_at']);
            $table->index(['project_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('time_tracking_entries', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'started_at']);
            $table->dropIndex(['project_id', 'started_at']);
        });
    }
};
