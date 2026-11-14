<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_calendars', function (Blueprint $table) {
            // Every DAV object call on a calendar looks its link up by calendar_id.
            $table->index('calendar_id');
            // sha256 of remote_url: a unique index on the 2048-character column itself is too long for older MariaDB.
            $table->char('remote_url_hash', 64)->nullable();
        });

        DB::table('external_calendars')->select(['id', 'remote_url'])->eachById(function (object $row) {
            DB::table('external_calendars')->where('id', $row->id)->update(['remote_url_hash' => hash('sha256', $row->remote_url)]);
        });

        Schema::table('external_calendars', function (Blueprint $table) {
            $table->char('remote_url_hash', 64)->nullable(false)->change();
            // A collection is connected once per account, even when two connects race.
            $table->unique(['external_calendar_account_id', 'remote_url_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('external_calendars', function (Blueprint $table) {
            // MariaDB may have dropped the foreign key's own index in favour of the unique one; keep the key covered.
            $table->index('external_calendar_account_id');
            $table->dropUnique(['external_calendar_account_id', 'remote_url_hash']);
            $table->dropIndex(['calendar_id']);
        });

        Schema::table('external_calendars', function (Blueprint $table) {
            $table->dropColumn('remote_url_hash');
        });
    }
};
