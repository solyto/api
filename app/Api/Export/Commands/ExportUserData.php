<?php

namespace App\Api\Export\Commands;

use App\Api\Export\Services\ExportService;
use App\Api\Users\Models\User;
use App\Shared\Models\ExportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExportUserData extends Command
{
    protected $signature = 'app:export
        {email : The email address of the user to export}
        {path=/var/www/tmp : Local directory to place the zip file}';

    protected $description = 'Export all user data as a zip file';

    public function handle(): void
    {
        $email = $this->argument('email');
        $outputDir = $this->argument('path');

        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User with email {$email} not found.");
            return;
        }

        $job = ExportJob::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'features' => ExportService::FEATURES,
        ]);

        $this->info('Starting export for user '.$user->email.' (job '.$job->id.')...');

        $service = new ExportService($user, $job);
        $relativePath = $service->export();

        $job->update(['status' => 'completed']);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $absoluteZip = Storage::disk('user_data')->path($relativePath);
        $destination = rtrim($outputDir, '/').'/export_'.$user->id.'.zip';
        copy($absoluteZip, $destination);

        $this->info("Export complete. Zip saved to: {$destination}");

        Storage::disk('user_data')->delete($relativePath);
        $job->delete();
    }
}
