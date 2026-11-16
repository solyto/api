<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clipboards', function (Blueprint $table) {
            $table->enum('type', ['text', 'image', 'file'])->default('text')->change();
            $table->string('file_name', 255)->nullable()->after('file_path');
            $table->unsignedInteger('file_size')->nullable()->after('file_name');
            $table->string('mime_type', 127)->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        DB::table('clipboards')->where('type', 'file')->delete();

        Schema::table('clipboards', function (Blueprint $table) {
            $table->dropColumn(['file_name', 'file_size', 'mime_type']);
            $table->enum('type', ['text', 'image'])->default('text')->change();
        });
    }
};
