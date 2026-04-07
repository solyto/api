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
        Schema::create('library_games', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->tinyInteger('rating')->nullable();
            $table->string('cover_path')->nullable();
            $table->enum('platform', ['pc', 'playstation', 'xbox', 'nintendo', 'mobile', 'other']);
            $table->string('developer')->nullable();
            $table->string('publisher')->nullable();
            $table->integer('publication_year')->nullable();
            $table->integer('playtime_hours')->nullable();
            $table->boolean('completed')->default(false);
            $table->string('link')->nullable();
            $table->date('started_at')->nullable();
            $table->date('finished_at')->nullable();
            $table->boolean('wishlist')->default(false);

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('tag_game', function (Blueprint $table) {
            $table->id();
            $table->uuid('game_id');
            $table->foreignId('tag_id');
            $table->timestamps();

            $table->foreign('game_id')
                  ->references('id')
                  ->on('library_games')
                  ->onDelete('cascade');

            $table->foreign('tag_id')
                  ->references('id')
                  ->on('tags')
                  ->onDelete('cascade');

            $table->unique(['game_id', 'tag_id']);
        });

        Schema::create('library_games_genres', function (Blueprint $table) {
            $table->id();
            $table->string('title');

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('library_genre_game', function (Blueprint $table) {
            $table->id();
            $table->uuid('game_id');
            $table->foreignId('genre_id');
            $table->timestamps();

            $table->foreign('game_id')
                  ->references('id')
                  ->on('library_games')
                  ->onDelete('cascade');

            $table->foreign('genre_id')
                  ->references('id')
                  ->on('library_games_genres')
                  ->onDelete('cascade');

            $table->unique(['game_id', 'genre_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag_game');
        Schema::dropIfExists('library_genre_game');
        Schema::dropIfExists('library_games_genres');
        Schema::dropIfExists('library_games');
    }
};
