<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_links', function (Blueprint $table) {
            $table->foreignUuid('source_note_id')->constrained('notes')->cascadeOnDelete();
            $table->foreignUuid('target_note_id')->constrained('notes')->cascadeOnDelete();

            $table->primary(['source_note_id', 'target_note_id']);
            $table->index('target_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_links');
    }
};
