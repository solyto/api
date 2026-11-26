<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('device_id', 64);
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
        });

        Schema::create('device_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->uuid('notification_id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url', 2048)->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_notifications');
        Schema::dropIfExists('notification_devices');
    }
};
