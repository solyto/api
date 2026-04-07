<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_tracking_project_category', function (Blueprint $table) {
            $table->uuid('project_id');
            $table->unsignedBigInteger('category_id');

            $table->foreign('project_id')
                ->references('id')
                ->on('time_tracking_projects')
                ->onDelete('cascade');

            $table->foreign('category_id')
                ->references('id')
                ->on('time_tracking_categories')
                ->onDelete('cascade');

            $table->primary(['project_id', 'category_id']);
        });

        $projects = DB::table('time_tracking_projects')
            ->whereNotNull('category_id')
            ->get(['id', 'category_id']);

        foreach ($projects as $project) {
            DB::table('time_tracking_project_category')->insert([
                'project_id' => $project->id,
                'category_id' => $project->category_id,
            ]);
        }

        Schema::table('time_tracking_projects', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('time_tracking_projects', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign('category_id')
                ->references('id')
                ->on('time_tracking_categories')
                ->onDelete('set null');
        });

        $pivots = DB::table('time_tracking_project_category')->get();

        foreach ($pivots as $pivot) {
            DB::table('time_tracking_projects')
                ->where('id', $pivot->project_id)
                ->update(['category_id' => $pivot->category_id]);
        }

        Schema::dropIfExists('time_tracking_project_category');
    }
};
