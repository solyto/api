<?php

use App\Api\Calendars\Jobs\InvalidateSharedCalendarCache;
use App\Dav\Backend\AppCalendarsPDO;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sabre took the occurrence bounds of an event stored with an override
 * before its master from the override, so time-range queries could drop the
 * series. AppCalendarsPDO stores them correctly since e56c330; this fixes the
 * rows written before. Only firstoccurence/lastoccurence change, and only
 * where they differ; each change is logged to the DAV log with the old
 * values so it can be undone by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('pgsql');
        $backend = new AppCalendarsPDO($db->getPdo());
        $calendarIds = [];

        $db->table('calendarobjects')
            ->where('componenttype', 'VEVENT')
            // On Postgres only the overrides leave the database; SQLite (tests) relies on the check below.
            ->when($db->getDriverName() === 'pgsql', fn ($query) => $query->whereRaw("position('RECURRENCE-ID'::bytea in calendardata) > 0"))
            ->select(['id', 'calendarid', 'uri', 'calendardata', 'firstoccurence', 'lastoccurence'])
            ->chunkById(50, function ($objects) use ($db, $backend, &$calendarIds) {
                foreach ($objects as $object) {
                    $data = is_resource($object->calendardata) ? stream_get_contents($object->calendardata) : $object->calendardata;

                    // Overrides carry a RECURRENCE-ID; skip the parse for everything else.
                    if (! is_string($data) || ! str_contains($data, 'RECURRENCE-ID')) {
                        continue;
                    }

                    $row = ['id' => $object->id, 'calendarid' => $object->calendarid, 'uri' => $object->uri];

                    try {
                        $bounds = $backend->masterOccurrenceBounds($data);
                    } catch (\Throwable $e) {
                        Log::channel('dav')->warning('Occurrence bounds migration: event could not be parsed, left unchanged', [...$row, 'error' => $e::class]);
                        continue;
                    }

                    if ($bounds === null
                        || ((int) $object->firstoccurence === $bounds['firstOccurence'] && (int) $object->lastoccurence === $bounds['lastOccurence'])) {
                        continue;
                    }

                    $db->table('calendarobjects')->where('id', $object->id)->update([
                        'firstoccurence' => $bounds['firstOccurence'],
                        'lastoccurence' => $bounds['lastOccurence'],
                    ]);

                    Log::channel('dav')->warning('Occurrence bounds migration: event bounds updated', [
                        ...$row,
                        'old' => ['firstoccurence' => $object->firstoccurence, 'lastoccurence' => $object->lastoccurence],
                        'new' => ['firstoccurence' => $bounds['firstOccurence'], 'lastoccurence' => $bounds['lastOccurence']],
                    ]);

                    $calendarIds[(int) $object->calendarid] = true;
                }
            });

        // The user_data store survives cache:clear on deploy.
        foreach (array_keys($calendarIds) as $calendarId) {
            InvalidateSharedCalendarCache::dispatchSync($calendarId);
        }
    }

    public function down(): void
    {
        // Nothing to restore: the old bounds were wrong, and the DAV log has them.
    }
};
