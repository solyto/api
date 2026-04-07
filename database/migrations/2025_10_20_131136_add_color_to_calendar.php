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
        Schema::table('nextcloud_calendars', function (Blueprint $table) {
            $table->string('color')->default('#e5e7eb')->nullable();
        });

        \Illuminate\Support\Facades\DB::statement('UPDATE nextcloud_calendars SET color = \'#e5e7eb\'');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nextcloud_calendars', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
