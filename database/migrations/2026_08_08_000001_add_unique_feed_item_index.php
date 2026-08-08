<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Items were previously matched on feed_item_id alone with no unique index, so
     * concurrent syncs of the same feed could store the same item more than once.
     * Collapse those duplicates before the index can be added.
     */
    public function up(): void
    {
        $duplicates = DB::table('feed_items')
            ->select('feed_id', 'feed_item_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('feed_id', 'feed_item_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('feed_items')
                ->where('feed_id', $duplicate->feed_id)
                ->where('feed_item_id', $duplicate->feed_item_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('feed_items', function (Blueprint $table) {
            $table->unique(['feed_id', 'feed_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('feed_items', function (Blueprint $table) {
            $table->dropUnique(['feed_id', 'feed_item_id']);
        });
    }
};
