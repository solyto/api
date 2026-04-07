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
        Schema::connection(config('webpush.database_connection'))
              ->table(config('webpush.table_name'), function (Blueprint $table) {
                  // Drop existing morphs columns
                  $table->dropMorphs('subscribable');
              });

        Schema::connection(config('webpush.database_connection'))
              ->table(config('webpush.table_name'), function (Blueprint $table) {
                  // Recreate with UUID support
                  $table->uuid('subscribable_id')->after('id');
                  $table->string('subscribable_type')->after('subscribable_id');
                  $table->index(['subscribable_id', 'subscribable_type'], 'push_subscriptions_subscribable_index');
              });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('webpush.database_connection'))
              ->table(config('webpush.table_name'), function (Blueprint $table) {
                  $table->dropIndex('push_subscriptions_subscribable_index');
                  $table->dropColumn(['subscribable_id', 'subscribable_type']);
              });

        Schema::connection(config('webpush.database_connection'))
              ->table(config('webpush.table_name'), function (Blueprint $table) {
                  $table->morphs('subscribable');
              });
    }
};
