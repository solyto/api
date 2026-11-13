<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_calendar_account_id')->constrained()->cascadeOnDelete();
            // Denormalised from the account so the per-user lookup on every DAV request is one indexed query.
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('remote_url', 2048);
            // pgsql calendars.id / calendarinstances.id of the native row; no cross-database FK, cleanup is explicit.
            $table->unsignedBigInteger('calendar_id');
            $table->unsignedBigInteger('instance_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_calendars');
    }
};
