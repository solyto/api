<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'library_music',
            'library_books',
            'library_links',
            'library_recipes',
            'library_movies',
            'library_games',
            'library_plants',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->text('cover_path')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'library_music',
            'library_books',
            'library_links',
            'library_recipes',
            'library_movies',
            'library_games',
            'library_plants',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('cover_path')->nullable()->change();
            });
        }
    }
};
