<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dev_requests', function (Blueprint $table) {
            $table->enum('status', ['backlog', 'pending', 'in-progress', 'waiting-for-deployment', 'waiting-for-user', 'cancelled', 'completed'])->default('pending')->change();
        });
    }

    public function down(): void
    {
        DB::table('dev_requests')->where('status', 'waiting-for-user')->update(['status' => 'pending']);

        Schema::table('dev_requests', function (Blueprint $table) {
            $table->enum('status', ['backlog', 'pending', 'in-progress', 'waiting-for-deployment', 'cancelled', 'completed'])->default('pending')->change();
        });
    }
};
