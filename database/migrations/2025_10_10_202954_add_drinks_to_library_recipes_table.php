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
        Schema::table('library_recipes', function (Blueprint $table) {
            $table->enum('type', ['breakfast', 'lunch', 'dinner', 'snack', 'dessert', 'drink', 'other'])->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('library_recipes', function (Blueprint $table) {
            $table->enum('type', ['breakfast', 'lunch', 'dinner', 'snack', 'dessert', 'other'])->nullable()->change();
        });
    }
};
