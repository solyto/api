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
        Schema::create('tag_note', function (Blueprint $table) {
            $table->id();
            $table->uuid('note_id');
            $table->foreignId('tag_id');
            $table->timestamps();

            $table->foreign('note_id')
                  ->references('id')
                  ->on('notes')
                  ->onDelete('cascade');

            $table->foreign('tag_id')
                  ->references('id')
                  ->on('tags')
                  ->onDelete('cascade');

            $table->unique(['note_id', 'tag_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag_note');
    }
};
