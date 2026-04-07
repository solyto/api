<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('widgets');
        });
        Schema::table('user_settings', function (Blueprint $table) {
            $table->json('widgets');
        });

        DB::statement('UPDATE user_settings SET widgets = \'[{"id":"your-day","visible":true,"order":1},{"id":"weather","visible":true,"order":2},{"id":"todos-high","visible":true,"order":3},{"id":"todos-relevant","visible":true,"order":4},{"id":"tracker-stats","visible":true,"order":5},{"id":"random-album","visible":true,"order":6},{"id":"random-quote","visible":true,"order":7}]\'');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('widgets');
        });
        Schema::table('user_settings', function (Blueprint $table) {
            $table->json('widgets')->nullable();
        });
    }
};
