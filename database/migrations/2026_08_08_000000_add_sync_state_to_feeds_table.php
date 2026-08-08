<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeds', function (Blueprint $table) {
            $table->dateTime('last_synced_at')->nullable()->default(null)->after('created_by');
            $table->text('last_error')->nullable()->default(null)->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('feeds', function (Blueprint $table) {
            $table->dropColumn(['last_synced_at', 'last_error']);
        });
    }
};
