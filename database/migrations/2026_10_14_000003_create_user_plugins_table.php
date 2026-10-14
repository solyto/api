<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_plugins', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('plugin');
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'plugin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_plugins');
    }
};
