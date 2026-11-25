<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The tracker tables replace the one-row-per-day table and the enabled_trackers list in the settings JSON.
// down() only brings back the empty table; the data itself comes back only from a backup.
return new class extends Migration
{
    private const array BUILT_IN_COLUMNS = [
        'mood', 'water', 'sports', 'sleep', 'dreams', 'food_quality', 'food_amount',
        'menstruation', 'alcohol', 'smoking', 'work', 'social_life',
    ];

    public function up(): void
    {
        DB::table('user_settings')
            ->whereNotNull('check_in_settings')
            ->select(['id', 'check_in_settings'])
            ->chunkById(500, fn (Collection $rows) => $rows->each(function ($row) {
                $settings = json_decode($row->check_in_settings, true);

                if (is_array($settings) && array_key_exists('enabled_trackers', $settings)) {
                    unset($settings['enabled_trackers']);
                    DB::table('user_settings')->where('id', $row->id)->update(['check_in_settings' => json_encode($settings)]);
                }
            }));

        Schema::dropIfExists('check_in');
    }

    public function down(): void
    {
        Schema::create('check_in', function (Blueprint $table) {
            $table->id();
            $table->date('date');

            foreach (self::BUILT_IN_COLUMNS as $column) {
                $table->tinyInteger($column)->nullable();
            }

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'date'], 'check_in_user_date_unique');
            $table->timestamps();
        });
    }
};
