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
        Schema::create('planner_entries', function (Blueprint $table) {
            $table->id();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->date('date');
            $table->string('title');
            $table->unsignedSmallInteger('start_minutes');
            $table->unsignedSmallInteger('duration_minutes');
            $table->boolean('is_all_day')->default(false);
            $table->string('color')->nullable();
            $table->uuid('todo_id')->nullable();
            $table->integer('sort_order')->default(0);

            $table->index(['user_id', 'date'], 'planner_entries_user_date_index');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planner_entries');
    }
};
