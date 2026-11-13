<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Our column on Sabre's table: true on the owner's instance while the
 * calendar has an accepted sharee. Sabre names its columns in every query,
 * so DAV doesn't see it. Keep it if a Sabre upgrade migrates calendarinstances.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('pgsql');
        $default = $db->getDriverName() === 'pgsql' ? 'FALSE' : '0';

        $db->statement("ALTER TABLE calendarinstances ADD COLUMN solyto_shared BOOLEAN NOT NULL DEFAULT {$default}");
        $db->statement('CREATE INDEX calendarinstances_calendarid ON calendarinstances (calendarid)');

        $db->table('calendarinstances')
            ->where('access', 1)
            ->whereIn('calendarid', fn (Builder $query) => $query
                ->select('calendarid')
                ->from('calendarinstances')
                ->where('access', '>', 1)
                ->where('share_invitestatus', 2))
            ->update(['solyto_shared' => true]);
    }

    public function down(): void
    {
        $db = DB::connection('pgsql');

        // SQLite can't drop an indexed column.
        $db->statement('DROP INDEX calendarinstances_calendarid');
        $db->statement('ALTER TABLE calendarinstances DROP COLUMN solyto_shared');
    }
};
