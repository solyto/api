<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('parent_token_id')
                ->nullable()
                ->unique()
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['parent_token_id']);
            $table->dropUnique(['parent_token_id']);
            $table->dropColumn('parent_token_id');
        });
    }
};
