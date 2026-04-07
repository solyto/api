<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendars', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->boolean('is_active')->default(true);
            $table->string('color')->default('#e5e7eb')->nullable();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('calendar_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('start_date');
            $table->integer('end_date');
            $table->boolean('is_all_day')->default(false);
            $table->integer('recurrence_end')->nullable();
            $table->text('recurrence_rule')->nullable();
            $table->string('location')->nullable();
            $table->string('timezone', 50)->nullable();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->uuid('calendar_id');
            $table->foreign('calendar_id')->references('id')->on('calendars')->onDelete('cascade');

            $table->index('start_date');
            $table->index('recurrence_end');

            $table->timestamps();
        });

        Schema::create('address_books', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('color')->default('#e5e7eb')->nullable();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->json('email')->nullable();
            $table->json('phone')->nullable();
            $table->string('organization')->nullable();
            $table->text('note')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->json('groups')->nullable();
            $table->string('photo')->nullable();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->uuid('address_book_id');
            $table->foreign('address_book_id')->references('id')->on('address_books')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_entries');
        Schema::dropIfExists('calendars');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('address_books');
    }
};
