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
        Schema::create('tracker', function (Blueprint $table) {
            $table->id();
            $table->date('date')->default(now());
            $table->tinyInteger('mood')->nullable();
            $table->tinyInteger('water')->nullable();
            $table->tinyInteger('sports')->nullable();
            $table->tinyInteger('sleep')->nullable();
            $table->tinyInteger('dreams')->nullable();
            $table->tinyInteger('food_quality')->nullable();
            $table->tinyInteger('food_amount')->nullable();

            $table->uuid('user_id');
$table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['user_id', 'date'], 'user_date_index');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tracker');
    }
};
