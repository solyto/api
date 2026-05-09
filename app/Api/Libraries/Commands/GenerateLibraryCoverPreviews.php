<?php

namespace App\Api\Libraries\Commands;

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Shared\Services\ImageTransformationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateLibraryCoverPreviews extends Command
{
    protected $signature = 'app:libraries:generate-cover-previews';
    protected $description = 'Generate preview images for all library covers';

    private const TYPES = [
        LibraryTypeEnum::MUSIC,
        LibraryTypeEnum::BOOK,
        LibraryTypeEnum::GAME,
        LibraryTypeEnum::PLANT,
        LibraryTypeEnum::RECIPE,
        LibraryTypeEnum::MOVIE,
    ];

    public function handle(ImageTransformationService $imageTransformation): int
    {
        foreach (self::TYPES as $type) {
            $this->processType($type, $imageTransformation);
        }

        return Command::SUCCESS;
    }

    private function processType(LibraryTypeEnum $type, ImageTransformationService $imageTransformation): void
    {
        $records = $type->getModel()::whereNotNull('cover_path')->get(['user_id', 'cover_path']);

        $this->info("Processing {$records->count()} {$type->value} covers...");
        $bar = $this->output->createProgressBar($records->count());
        $bar->start();

        $skipped  = 0;
        $created  = 0;
        $failed   = 0;

        foreach ($records as $record) {
            $diskPath    = $record->user_id . '/' . $type->value . '/' . $record->cover_path;
            $previewPath = $this->previewPath($diskPath);

            if (Storage::disk('user_data')->exists($previewPath)) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $result = $imageTransformation->generatePreview('user_data', $diskPath);
            $result ? $created++ : $failed++;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  created: {$created}, skipped: {$skipped}, failed: {$failed}");
    }

    private function previewPath(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base      = $extension ? substr($path, 0, -strlen($extension) - 1) : $path;

        return $base . '_preview' . ($extension ? '.' . $extension : '');
    }
}
