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
        // Migration for address books
        Schema::create('nextcloud_address_books', function (Blueprint $table) {
            $table->id();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->string('name');
            $table->string('url');

            $table->timestamps();
        });

        // Migration for contacts
        Schema::create('nextcloud_contacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('address_book_id');

            $table->foreign('address_book_id')
                  ->references('id')
                  ->on('nextcloud_address_books')
                  ->onDelete('cascade');

            $table->string('uid')->unique();
            $table->string('full_name');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('prefix')->nullable();
            $table->string('suffix')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('organization')->nullable();
            $table->string('title')->nullable();
            $table->text('note')->nullable();
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->string('url')->nullable();
            $table->string('etag')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nextcloud_contacts');
        Schema::dropIfExists('nextcloud_address_books');
    }
};
