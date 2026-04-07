<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clipboards', function (Blueprint $table) {
            $table->enum('type', ['text', 'image'])->default('text')->after('content');
            $table->string('file_path', 255)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('clipboards', function (Blueprint $table) {
            $table->dropColumn(['type', 'file_path']);
        });
    }
};
