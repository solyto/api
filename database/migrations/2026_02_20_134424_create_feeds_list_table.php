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
        Schema::create('feed_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('whitelist')->nullable()->default(null);
            $table->text('blacklist')->nullable()->default(null);
            $table->foreignUuid('feed_id')->constrained('feeds')->onDelete('cascade');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });

        DB::table('feeds')->orderBy('id')->each(function ($feed) {
            DB::table('feed_subscriptions')->insert([
                'id'         => \Illuminate\Support\Str::uuid(),
                'title'      => $feed->title,
                'whitelist'  => $feed->keywords ?? null,
                'blacklist'  => $feed->blacklist ?? null,
                'feed_id'    => $feed->id,
                'user_id'    => $feed->user_id,
                'created_at' => $feed->created_at,
                'updated_at' => $feed->updated_at,
            ]);
        });

        Schema::table('feeds', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'keywords', 'blacklist']);
            $table->string('created_by')->nullable()->default(null)->after('url');
        });

        Schema::table('feed_items', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('feeds', function (Blueprint $table) {
            $table->uuid('user_id')->nullable();
            $table->text('keywords')->nullable();
            $table->text('blacklist')->nullable();
            $table->dropColumn('created_by');
        });

        Schema::table('feed_items', function (Blueprint $table) {
            $table->uuid('user_id')->nullable();
        });

        Schema::table('feed_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['feed_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::dropIfExists('feed_subscriptions');
    }
};
