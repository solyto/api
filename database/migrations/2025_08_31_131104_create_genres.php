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
        Schema::create('library_music_genres', function (Blueprint $table) {
            $table->id();
            $table->string('title');

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('library_genre_music', function (Blueprint $table) {
            $table->id();
            $table->uuid('music_id');
            $table->foreignId('genre_id');
            $table->timestamps();

            $table->foreign('music_id')
                  ->references('id')
                  ->on('library_music')
                  ->onDelete('cascade');

            $table->foreign('genre_id')
                  ->references('id')
                  ->on('library_music_genres')
                  ->onDelete('cascade');

            $table->unique(['music_id', 'genre_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_genre_music');
        Schema::dropIfExists('library_music_genres');
    }
};
