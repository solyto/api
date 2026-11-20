<?php

use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Services\CheckInService;
use App\Api\Export\Services\CheckInExportService;
use App\Api\Users\Models\User;

function exportCheckIns(User $user): array
{
    $dir = sys_get_temp_dir().'/check-in-export-'.uniqid();
    mkdir($dir);
    app(CheckInExportService::class)->export($user, $dir.'/check_ins.csv');

    $read = fn (string $file) => is_file("{$dir}/{$file}") ? array_map('str_getcsv', file("{$dir}/{$file}", FILE_IGNORE_NEW_LINES)) : null;
    $files = ['builtIn' => $read('check_ins.csv'), 'own' => $read(CheckInExportService::OWN_TRACKERS_FILE)];
    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);

    return $files;
}

describe('Check-in export', function () {
    it('exports the built-in trackers as before and the own trackers in a second file', function () {
        $user = makeUser();
        $glasses = CheckInTracker::factory()->forUser($user)->ofType(CheckInTrackerType::Count)->create(['name' => 'Glasses of water']);
        app(CheckInService::class)->saveDay($user, '2026-10-01', ['mood' => 4]);
        app(CheckInService::class)->setValue($user, $glasses, '2026-10-01', 6);

        $files = exportCheckIns($user);

        expect($files['builtIn'][0][0])->toBe('Date')
            ->and($files['builtIn'][1][0])->toBe('2026-10-01')
            ->and($files['builtIn'][1][1])->toBe('4')
            ->and($files['own'])->toBe([
                ['Date', 'Tracker', 'Type', 'Value'],
                ['2026-10-01', 'Glasses of water', 'count', '6'],
            ]);
    });

    it('writes no second file when the user has no own trackers', function () {
        $user = makeUser();

        expect(exportCheckIns($user)['own'])->toBeNull();
    });

    it('never exports another user\'s trackers', function () {
        $user = makeUser();
        $other = makeUser();
        $tracker = CheckInTracker::factory()->forUser($other)->create(['name' => 'Secret']);
        app(CheckInService::class)->setValue($other, $tracker, '2026-10-01', 1);

        expect(exportCheckIns($user)['own'])->toBeNull();
    });
});
