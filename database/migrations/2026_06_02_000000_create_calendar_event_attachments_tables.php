<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_event_todos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('calendar_object_id');
            $table->uuid('todo_id');
            $table->uuid('user_id');
            $table->timestamps();

            $table->unique(['calendar_object_id', 'todo_id']);
            $table->foreign('todo_id')->references('id')->on('todos')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('calendar_event_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('calendar_object_id');
            $table->uuid('note_id');
            $table->uuid('user_id');
            $table->timestamps();

            $table->unique(['calendar_object_id', 'note_id']);
            $table->foreign('note_id')->references('id')->on('notes')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_todos');
        Schema::dropIfExists('calendar_event_notes');
    }
};
