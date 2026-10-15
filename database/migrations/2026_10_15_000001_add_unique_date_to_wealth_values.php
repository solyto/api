<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner decision (job river, 2026-10-15): per field and day keep the most
        // recently created value and drop the others, no backup.
        $duplicates = DB::table('wealth_values')
            ->select('field_id', 'date')
            ->groupBy('field_id', 'date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $ids = DB::table('wealth_values')
                ->where('field_id', $duplicate->field_id)
                ->where('date', $duplicate->date)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id');

            DB::table('wealth_values')->whereIn('id', $ids->slice(1)->all())->delete();
        }

        Schema::table('wealth_values', function (Blueprint $table) {
            $table->unique(['field_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('wealth_values', function (Blueprint $table) {
            $table->dropUnique(['field_id', 'date']);
        });
    }
};
