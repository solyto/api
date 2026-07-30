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
        Schema::create('library_videos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('video_id')->nullable();
            $table->string('url', 1000);
            $table->text('cover_path')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->integer('sort_order')->default(0);

            $table->foreignId('category_id')->nullable();
            $table->foreign('category_id')
                  ->references('id')
                  ->on('library_videos_categories')
                  ->onDelete('set null');

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
        Schema::dropIfExists('library_videos');
    }
};
