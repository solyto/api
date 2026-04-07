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
        Schema::create('dev_requests', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['feature', 'bug']);
            $table->enum('status', ['backlog', 'pending', 'in-progress', 'cancelled', 'completed'])->default('pending');
            $table->tinyInteger('priority')->default(1)->nullable();
            $table->string('title');
            $table->text('description');
            $table->string('screenshot')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dev_requests');
    }
};
