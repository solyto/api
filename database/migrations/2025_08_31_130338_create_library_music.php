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
        Schema::create('library_music', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('artist');
            $table->enum('type', ['cd', 'vinyl', 'digital'])->nullable();
            $table->enum('format', ['album', 'single', 'compilation'])->nullable();
            $table->enum('condition', ['mint', 'very-good', 'good', 'medium', 'poor', 'very-poor'])->nullable();
            $table->tinyInteger('rating')->nullable();
            $table->string('acquired_where')->nullable();
            $table->string('additional_info')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('link')->nullable();

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
        Schema::dropIfExists('library_music');
    }
};
