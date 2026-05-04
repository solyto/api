<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_plants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('latin_name')->nullable();
            $table->enum('location', ['indoor', 'outdoor', 'both'])->nullable();
            $table->enum('sunlight', ['full_sun', 'partial_sun', 'indirect', 'shade'])->nullable();
            $table->string('current_size')->nullable();
            $table->string('max_size')->nullable();
            $table->date('acquired_at')->nullable();
            $table->boolean('winter_hardy')->nullable();
            $table->text('instructions')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('link')->nullable();
            $table->boolean('wishlist')->default(false);

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_plants');
    }
};
