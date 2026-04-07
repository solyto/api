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
        Schema::create('verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id')->unique();
            $table->string('token')->index();
            $table->dateTime('expires_at');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('confirmation_token');
            $table->dropColumn('confirmation_token_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_tokens');

        Schema::table('users', function (Blueprint $table) {
            $table->string('confirmation_token')->after('role')->nullable();
            $table->dateTime('confirmation_token_expires_at')->after('confirmation_token')->nullable();
        });
    }
};
