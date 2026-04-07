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
        Schema::create('friend_requests', function (Blueprint $table) {
            $table->id();

            $table->uuid('sender_id');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            $table->uuid('receiver_id');
            $table->foreign('receiver_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['sender_id', 'receiver_id']);
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');

            $table->timestamps();
        });

        Schema::create('friends', function (Blueprint $table) {
            $table->id();

            $table->uuid('user_id_1');
            $table->foreign('user_id_1')->references('id')->on('users')->onDelete('cascade');
            $table->uuid('user_id_2');
            $table->foreign('user_id_2')->references('id')->on('users')->onDelete('cascade');

            $table->timestamp('friends_since');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('friend_requests');
        Schema::dropIfExists('friends');
    }
};
