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
        Schema::create('library_movies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->tinyInteger('rating')->nullable();
            $table->string('cover_path')->nullable();
            $table->enum('category', ['movie', 'series']);
            $table->integer('publication_year')->nullable();
            $table->string('link')->nullable();
            $table->date('started_at')->nullable();
            $table->date('finished_at')->nullable();
            $table->boolean('wishlist')->default(false);

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('tag_movie', function (Blueprint $table) {
            $table->id();
            $table->uuid('movie_id');
            $table->foreignId('tag_id');
            $table->timestamps();

            $table->foreign('movie_id')
                  ->references('id')
                  ->on('library_movies')
                  ->onDelete('cascade');

            $table->foreign('tag_id')
                  ->references('id')
                  ->on('tags')
                  ->onDelete('cascade');

            $table->unique(['movie_id', 'tag_id']);
        });

        Schema::create('library_movies_genres', function (Blueprint $table) {
            $table->id();
            $table->string('title');

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('library_genre_movie', function (Blueprint $table) {
            $table->id();
            $table->uuid('movie_id');
            $table->foreignId('genre_id');
            $table->timestamps();

            $table->foreign('movie_id')
                  ->references('id')
                  ->on('library_movies')
                  ->onDelete('cascade');

            $table->foreign('genre_id')
                  ->references('id')
                  ->on('library_movies_genres')
                  ->onDelete('cascade');

            $table->unique(['movie_id', 'genre_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag_movie');
        Schema::dropIfExists('library_genre_movie');
        Schema::dropIfExists('library_movies_genres');
        Schema::dropIfExists('library_movies');
    }
};
