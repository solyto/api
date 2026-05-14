<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_in', function (Blueprint $table) {
            $table->dropIndex('user_date_index');
            $table->unique(['user_id', 'date'], 'check_in_user_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('check_in', function (Blueprint $table) {
            $table->dropUnique('check_in_user_date_unique');
            $table->index(['user_id', 'date'], 'user_date_index');
        });
    }
};
