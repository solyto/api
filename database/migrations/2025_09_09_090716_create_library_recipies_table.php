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
        Schema::create('library_recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->tinyInteger('rating')->nullable();
            $table->integer('time_to_make')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('link')->nullable();
            $table->text('description')->nullable();
            $table->string('ingredients')->nullable();
            $table->enum('type', ['breakfast', 'lunch', 'dinner', 'snack', 'dessert', 'other'])->nullable();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_recipes');
    }
};
