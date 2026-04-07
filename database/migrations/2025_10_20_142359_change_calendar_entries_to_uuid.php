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
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            Schema::drop('nextcloud_calendar_entries');
            Schema::create('nextcloud_calendar_entries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->string('location')->nullable();
                $table->dateTime('start_date')->nullable();
                $table->dateTime('end_date')->nullable();
                $table->boolean('is_all_day')->default(false);
                $table->string('etag')->nullable();
                $table->string('url', 1000)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->foreignId('calendar_id')->constrained('nextcloud_calendars')->onDelete('cascade');
                $table->uuid('user_id');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        } else {
            \Illuminate\Support\Facades\DB::statement('DELETE FROM nextcloud_calendar_entries');

            Schema::table('nextcloud_calendar_entries', function (Blueprint $table) {
                $table->bigInteger('id')->change();
            });

            Schema::table('nextcloud_calendar_entries', function (Blueprint $table) {
                $table->dropPrimary();
                $table->dropColumn('id');
                $table->uuid('id')->primary()->first();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            Schema::drop('nextcloud_calendar_entries');
            Schema::create('nextcloud_calendar_entries', function (Blueprint $table) {
                $table->id();
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->string('location')->nullable();
                $table->dateTime('start_date')->nullable();
                $table->dateTime('end_date')->nullable();
                $table->boolean('is_all_day')->default(false);
                $table->string('etag')->nullable();
                $table->string('url', 1000)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->foreignId('calendar_id')->constrained('nextcloud_calendars')->onDelete('cascade');
                $table->uuid('user_id');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        } else {
            \Illuminate\Support\Facades\DB::statement('DELETE FROM nextcloud_calendar_entries');

            Schema::table('nextcloud_calendar_entries', function (Blueprint $table) {
                $table->dropPrimary();
                $table->dropColumn('id');
                $table->id()->first();
            });
        }
    }
};
