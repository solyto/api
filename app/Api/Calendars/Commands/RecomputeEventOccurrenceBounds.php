<?php

namespace App\Api\Calendars\Commands;

use App\Dav\Backend\AppCalendarsPDO;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecomputeEventOccurrenceBounds extends Command
{
    protected $signature = 'app:calendars:recompute-event-bounds {--apply : Write the new bounds (default is a dry run)}';
    protected $description = 'Recompute firstoccurence/lastoccurence for recurring events stored with an override before the master';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $connection = DB::connection('pgsql');
        $backend = new AppCalendarsPDO($connection->getPdo());
        $query = $connection->table('calendarobjects')->where('componenttype', 'VEVENT');

        $total = $query->clone()->count();
        $this->info(($apply ? 'Recomputing' : '[DRY RUN] Scanning') . " {$total} events...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $stale = [];
        $unreadable = [];

        $query->select(['id', 'calendarid', 'uri', 'calendardata', 'firstoccurence', 'lastoccurence'])
            ->chunkById(50, function ($objects) use ($backend, $connection, $apply, $bar, &$stale, &$unreadable) {
                foreach ($objects as $object) {
                    $bar->advance();

                    $data = is_resource($object->calendardata) ? stream_get_contents($object->calendardata) : $object->calendardata;

                    // Overrides carry a RECURRENCE-ID; skip the parse for everything else.
                    if (!is_string($data) || !str_contains($data, 'RECURRENCE-ID')) {
                        continue;
                    }

                    try {
                        $bounds = $backend->masterOccurrenceBounds($data);
                    } catch (\Throwable) {
                        $unreadable[] = $object;
                        continue;
                    }

                    if ($bounds === null
                        || ((int) $object->firstoccurence === $bounds['firstOccurence'] && (int) $object->lastoccurence === $bounds['lastOccurence'])) {
                        continue;
                    }

                    $stale[] = $object;

                    if ($apply) {
                        $connection->table('calendarobjects')->where('id', $object->id)->update([
                            'firstoccurence' => $bounds['firstOccurence'],
                            'lastoccurence' => $bounds['lastOccurence'],
                        ]);
                    }
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("Scanned {$total}. Stale bounds: " . count($stale) . '.' . ($apply ? ' Updated.' : ' Pass --apply to write changes.'));

        foreach ($stale as $object) {
            $this->line("  object #{$object->id} (calendar #{$object->calendarid}, uri={$object->uri})");
        }

        if ($unreadable !== []) {
            $this->warn('Could not parse ' . count($unreadable) . ' event(s), left unchanged:');
            foreach ($unreadable as $object) {
                $this->line("  object #{$object->id} (calendar #{$object->calendarid}, uri={$object->uri})");
            }
        }

        return Command::SUCCESS;
    }
}
