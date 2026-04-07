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
        Schema::create('library_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('summary')->nullable();
            $table->string('author')->nullable();
            $table->text('quote');
            $table->string('source')->nullable();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('tag_quote', function (Blueprint $table) {
            $table->id();
            $table->uuid('quote_id');
            $table->foreignId('tag_id');
            $table->timestamps();

            $table->foreign('quote_id')
                  ->references('id')
                  ->on('library_quotes')
                  ->onDelete('cascade');

            $table->foreign('tag_id')
                  ->references('id')
                  ->on('tags')
                  ->onDelete('cascade');

            $table->unique(['quote_id', 'tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag_quote');
        Schema::dropIfExists('library_quotes');
    }
};
