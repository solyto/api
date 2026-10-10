<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_tracking_projects', function (Blueprint $table) {
            $table->string('color')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('time_tracking_projects', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
