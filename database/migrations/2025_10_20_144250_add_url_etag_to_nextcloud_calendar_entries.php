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
        Schema::table('nextcloud_calendar_entries', function (Blueprint $table) {
            $table->string('etag')->nullable();
            $table->string('url', 1000)->nullable();
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
