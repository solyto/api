<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('weather_city')->nullable();
            $table->decimal('weather_latitude', 10, 7)->nullable();
            $table->decimal('weather_longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn(['weather_city', 'weather_latitude', 'weather_longitude']);
        });
    }
};
