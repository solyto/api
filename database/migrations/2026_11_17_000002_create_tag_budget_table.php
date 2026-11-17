<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_budget', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id');
            $table->foreignId('tag_id');
            $table->timestamps();

            $table->foreign('budget_id')
                  ->references('id')
                  ->on('budgets')
                  ->onDelete('cascade');

            $table->foreign('tag_id')
                  ->references('id')
                  ->on('tags')
                  ->onDelete('cascade');

            $table->unique(['budget_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_budget');
    }
};
