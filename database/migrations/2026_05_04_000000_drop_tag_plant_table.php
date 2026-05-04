<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tag_plant');

        Schema::table('library_plants', function (Blueprint $table) {
            $table->dropColumn('wishlist');
        });
    }

    public function down(): void
    {
        Schema::table('library_plants', function (Blueprint $table) {
            $table->boolean('wishlist')->default(false);
        });

        Schema::create('tag_plant', function (Blueprint $table) {
            $table->id();
            $table->uuid('plant_id');
            $table->foreignId('tag_id');
            $table->timestamps();

            $table->foreign('plant_id')
                  ->references('id')
                  ->on('library_plants')
                  ->onDelete('cascade');

            $table->foreign('tag_id')
                  ->references('id')
                  ->on('tags')
                  ->onDelete('cascade');

            $table->unique(['plant_id', 'tag_id']);
        });
    }
};
