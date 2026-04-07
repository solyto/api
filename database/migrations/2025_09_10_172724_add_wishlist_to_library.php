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
        Schema::table('library_music', function (Blueprint $table) {
            $table->boolean('wishlist')->default(false);
        });
        Schema::table('library_books', function (Blueprint $table) {
            $table->boolean('wishlist')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('library_music', function (Blueprint $table) {
            $table->dropColumn('wishlist');
        });
        Schema::table('library_books', function (Blueprint $table) {
            $table->dropColumn('wishlist');
        });
    }
};
