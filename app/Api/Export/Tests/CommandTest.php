<?php

use App\Shared\Models\ExportJob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

describe('app:export command', function () {
    it('writes the zip to the output directory and removes the job', function () {
        Storage::fake('user_data');
        $user = makeUser(['email' => 'export@example.com']);
        $outputDir = sys_get_temp_dir().'/solyto-export-test-'.uniqid();

        try {
            $this->artisan('app:export', ['email' => 'export@example.com', 'path' => $outputDir])
                ->assertSuccessful();

            expect(file_exists($outputDir.'/export_'.$user->id.'.zip'))->toBeTrue();
            expect(ExportJob::where('user_id', $user->id)->exists())->toBeFalse();
        } finally {
            File::deleteDirectory($outputDir);
        }
    });

    it('fails for an unknown email', function () {
        $this->artisan('app:export', ['email' => 'missing@example.com'])
            ->expectsOutputToContain('not found');
    });
});
