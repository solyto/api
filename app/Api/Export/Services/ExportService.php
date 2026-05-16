<?php

namespace App\Api\Export\Services;

use App\Api\Users\Models\User;
use App\Dav\Services\DavService;
use App\Shared\Models\ExportJob;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ExportService
{
    public const array FEATURES = [
        'todos',
        'notes',
        'calendars',
        'contacts',
        'feeds',
        'links',
        'music',
        'books',
        'games',
        'recipes',
        'quotes',
        'checkIn',
        'timeTracking',
        'financesIncome',
        'financesWealth',
    ];

    public function __construct(
        private readonly User $user,
        private readonly ExportJob $job,
        private readonly DavService $davService,
    ) {}

    public function export(): string
    {
        $tmpPath = $this->getStoragePath();
        mkdir($tmpPath, 0755, true);

        $features = $this->job->features ?? [];

        foreach ($features as $feature) {
            match ($feature) {
                'music' => app(MusicExportService::class)->export($this->user, $tmpPath.'/music.csv'),
                'books' => app(BookExportService::class)->export($this->user, $tmpPath.'/books.csv'),
                'games' => app(GameExportService::class)->export($this->user, $tmpPath.'/games.csv'),
                'recipes' => app(RecipeExportService::class)->export($this->user, $tmpPath.'/recipes.csv'),
                'todos' => app(TodoExportService::class)->export($this->user, $tmpPath.'/todos.csv'),
                'quotes' => app(QuoteExportService::class)->export($this->user, $tmpPath.'/quotes.csv'),
                'links' => app(LinkExportService::class)->export($this->user, $tmpPath.'/links.html'),
                'calendars' => app(CalendarExportService::class)->export($this->user, $tmpPath.'/calendar.ics'),
                'contacts' => app(ContactExportService::class)->export($this->user, $tmpPath.'/contacts.vcf'),
                'notes' => app(NoteExportService::class)->export($this->user, $tmpPath.'/notes'),
                'checkIn' => app(CheckInExportService::class)->export($this->user, $tmpPath.'/check_ins.csv'),
                'timeTracking' => app(TimeTrackingExportService::class)->export($this->user, $tmpPath.'/time_tracking.csv'),
                'feeds' => app(FeedExportService::class)->export($this->user, $tmpPath.'/feeds.opml'),
                'financesIncome' => app(FinanceIncomeExportService::class)->export($this->user, $tmpPath.'/finances_income.csv'),
                'financesWealth' => app(FinanceWealthExportService::class)->export($this->user, $tmpPath.'/finances_wealth.csv'),
                default => null,
            };
        }

        $zipPath = $this->createZip($tmpPath);
        $this->deleteDir($tmpPath);

        return $zipPath;
    }

    private function getStoragePath(): string
    {
        return Storage::disk('user_data')->path($this->user->id.'/exports/'.$this->job->id);
    }

    private function createZip(string $sourceDir): string
    {
        $zipFilename = 'export_'.$this->job->id.'.zip';
        $relativePath = $this->user->id.'/'.$zipFilename;
        $zipPath = Storage::disk('user_data')->path($relativePath);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $folderName = 'export_'.$this->job->id;

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $fileRelativePath = substr($filePath, strlen($sourceDir) + 1);
            $zip->addFile($filePath, $folderName.'/'.$fileRelativePath);
        }

        $zip->close();

        return $relativePath;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        rmdir($dir);
    }
}
