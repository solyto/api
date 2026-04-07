<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_tracking_categories', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('color')->nullable();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('time_tracking_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();

            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('time_tracking_categories')->onDelete('set null');

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('time_tracking_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('description')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('stopped_at')->nullable();
            $table->integer('duration_minutes')->default(0);

            $table->uuid('project_id');
            $table->foreign('project_id')->references('id')->on('time_tracking_projects')->onDelete('cascade');

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_tracking_entries');
        Schema::dropIfExists('time_tracking_projects');
        Schema::dropIfExists('time_tracking_categories');
    }
};
