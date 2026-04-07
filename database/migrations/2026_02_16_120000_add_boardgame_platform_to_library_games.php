<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE library_games MODIFY COLUMN platform ENUM('pc', 'playstation', 'xbox', 'nintendo', 'mobile', 'boardgame', 'other') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE library_games MODIFY COLUMN platform ENUM('pc', 'playstation', 'xbox', 'nintendo', 'mobile', 'other') NOT NULL");
    }
};
