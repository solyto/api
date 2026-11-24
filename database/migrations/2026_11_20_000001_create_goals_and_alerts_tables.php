<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `source` and `rule` are strings, not enums, so a new source or rule needs no schema change.
        // Each source gets its own nullable reference column with a real foreign key.
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('source', 32);
            $table->foreignId('check_in_tracker_id')->nullable()->constrained('check_in_trackers')->cascadeOnDelete();
            $table->enum('period', ['day', 'week', 'month']);
            $table->enum('comparison', ['at_least', 'at_most']);
            $table->decimal('target', 12, 2);
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('source', 32);
            $table->foreignId('check_in_tracker_id')->nullable()->constrained('check_in_trackers')->cascadeOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained('goals')->cascadeOnDelete();
            $table->string('rule', 32);
            $table->decimal('threshold', 12, 2)->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('suggestion', 32)->nullable();
            $table->boolean('is_triggered')->default(false);
            $table->date('last_triggered_on')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('goals');
    }
};
