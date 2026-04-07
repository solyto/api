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
        Schema::create('library_books', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('author');
            $table->integer('pages')->nullable();
            $table->integer('current_page')->nullable();
            $table->tinyInteger('rating')->nullable();
            $table->string('lent_to')->nullable();
            $table->string('is_where')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('link')->nullable();
            $table->date('started_at')->nullable();
            $table->date('finished_at')->nullable();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('library_books_genres', function (Blueprint $table) {
            $table->id();
            $table->string('title');

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('library_genre_book', function (Blueprint $table) {
            $table->id();
            $table->uuid('book_id');
            $table->foreignId('genre_id');
            $table->timestamps();

            $table->foreign('book_id')
                  ->references('id')
                  ->on('library_books')
                  ->onDelete('cascade');

            $table->foreign('genre_id')
                  ->references('id')
                  ->on('library_books_genres')
                  ->onDelete('cascade');

            $table->unique(['book_id', 'genre_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_genre_book');
        Schema::dropIfExists('library_books_genres');
        Schema::dropIfExists('library_books');
    }
};
