<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todo_workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('title');

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('workspace_category', function (Blueprint $table) {
            // Changed from unsignedInteger to foreignId to match the id columns
            $table->foreignId('category_id');
            $table->foreignId('workspace_id');
            $table->timestamps();

            $table->foreign('category_id')
                  ->references('id')
                  ->on('todo_categories')
                  ->onDelete('cascade');

            $table->foreign('workspace_id')
                  ->references('id')
                  ->on('todo_workspaces')
                  ->onDelete('cascade');

            $table->unique(['category_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_category');
        Schema::dropIfExists('todo_workspaces');
    }
};
