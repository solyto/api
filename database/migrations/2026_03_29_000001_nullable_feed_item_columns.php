<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_items', function (Blueprint $table) {
            $table->text('description')->nullable()->default(null)->change();
            $table->string('link')->nullable()->default(null)->change();
            $table->dateTime('published_at')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('feed_items', function (Blueprint $table) {
            $table->text('description')->nullable(false)->change();
            $table->string('link')->nullable(false)->change();
            $table->dateTime('published_at')->nullable(false)->change();
        });
    }
};
