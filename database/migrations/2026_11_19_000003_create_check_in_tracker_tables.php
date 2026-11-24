<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_in_trackers', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('key', 32)->nullable();
            $table->string('name', 50)->nullable();
            $table->enum('type', ['scale', 'yes_no', 'count', 'sport']);
            $table->string('icon', 64)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->boolean('scored')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index(['user_id', 'position']);
        });

        Schema::create('check_in_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_in_tracker_id')->constrained('check_in_trackers')->cascadeOnDelete();
            $table->date('date');
            $table->integer('value');
            $table->timestamps();

            $table->unique(['check_in_tracker_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_in_values');
        Schema::dropIfExists('check_in_trackers');
    }
};
