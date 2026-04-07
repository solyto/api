<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->enum('recurrence_frequency', ['daily', 'weekly', 'monthly', 'yearly'])->nullable()->after('due_at');
            $table->tinyInteger('recurrence_interval')->unsigned()->default(1)->after('recurrence_frequency');
            $table->date('recurrence_ends_at')->nullable()->after('recurrence_interval');
            $table->uuid('parent_task_id')->nullable()->after('recurrence_ends_at');
            $table->boolean('auto_generated')->default(false)->after('parent_task_id');

            $table->foreign('parent_task_id')->references('id')->on('todos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropForeign(['parent_task_id']);
            $table->dropColumn(['recurrence_frequency', 'recurrence_interval', 'recurrence_ends_at', 'parent_task_id', 'auto_generated']);
        });
    }
};
