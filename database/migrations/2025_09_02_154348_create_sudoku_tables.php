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
        Schema::create('sudoku_puzzles', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', length: 100)->unique();
            $table->string('grid', length: 500);
            $table->string('type', length: 30);
            $table->string('source', length: 100);
            $table->string('difficulty', length: 10);
            $table->string('description', length: 500);
            $table->timestamps();
        });

        Schema::create('sudoku_shared_states', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', length: 100)->unique();
            $table->string('grid', length: 500);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sudoku_puzzles');
        Schema::dropIfExists('sudoku_shared_states');
    }
};
