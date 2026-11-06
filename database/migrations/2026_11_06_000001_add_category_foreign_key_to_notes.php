<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notes whose folder was deleted before this key existed point at a
        // missing category; the key cannot be added while they do.
        DB::table('notes')
            ->whereNotNull('category_id')
            ->whereNotExists(fn(Builder $query) => $query
                ->select(DB::raw(1))
                ->from('note_categories')
                ->whereColumn('note_categories.id', 'notes.category_id'))
            ->update(['category_id' => null]);

        Schema::table('notes', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('note_categories')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });
    }
};
