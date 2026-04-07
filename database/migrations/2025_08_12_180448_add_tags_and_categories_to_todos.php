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
        Schema::table('todos', function (Blueprint $table) {
            $table->foreignId('category_id')
                  ->nullable()
                  ->constrained('todo_categories')
                  ->nullOnDelete();
        });

        Schema::create('tag_todo', function (Blueprint $table) {
            $table->id();
            $table->uuid('todo_id');
            $table->foreignId('tag_id');
            $table->timestamps();

            $table->foreign('todo_id')
                  ->references('id')
                  ->on('todos')
                  ->onDelete('cascade');

            $table->foreign('tag_id')
                  ->references('id')
                  ->on('tags')
                  ->onDelete('cascade');

            $table->unique(['todo_id', 'tag_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
            Schema::dropIfExists('tag_todo');
        });
    }
};
