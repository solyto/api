<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The preceding migration rebuilds this table from scratch on sqlite and already
        // includes both columns there, so only add what is actually missing.
        Schema::table('nextcloud_calendar_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('nextcloud_calendar_entries', 'etag')) {
                $table->string('etag')->nullable();
            }

            if (!Schema::hasColumn('nextcloud_calendar_entries', 'url')) {
                $table->string('url', 1000)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nextcloud_calendar_entries', function (Blueprint $table) {
            $table->dropColumn('etag');
            $table->dropColumn('url');
        });
    }
};
