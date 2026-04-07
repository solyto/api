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
        Schema::create('wealth_fields', function (Blueprint $table) {
            $table->id();
            $table->string('title');

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });
        Schema::create('wealth_values', function (Blueprint $table) {
            $table->id();

            $table->date('date');
            $table->float('value');
            $table->unsignedBigInteger('field_id');
            $table->foreign('field_id')->references('id')->on('wealth_fields')->onDelete('cascade');

            $table->timestamps();
        });
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->enum('type', ['income', 'expense']);
            $table->float('value');

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wealth_values');
        Schema::dropIfExists('wealth_fields');
        Schema::dropIfExists('budgets');
    }
};
