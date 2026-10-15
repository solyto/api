<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_values', function (Blueprint $table) {
            $table->id();

            $table->date('date');
            $table->float('value');
            $table->unsignedBigInteger('budget_id');
            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('cascade');

            $table->timestamps();

            $table->unique(['budget_id', 'date']);
        });

        $now = Carbon::now();

        DB::table('budgets')->orderBy('id')->chunkById(500, function ($budgets) use ($now) {
            DB::table('budget_values')->insert($budgets->map(fn ($budget) => [
                'date' => Carbon::parse($budget->updated_at ?? $budget->created_at ?? $now)->toDateString(),
                'value' => $budget->value,
                'budget_id' => $budget->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_values');
    }
};
