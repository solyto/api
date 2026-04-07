<?php

namespace App\Api\Notes\Services;

use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NoteImportService
{
    public function importFile(UploadedFile $file, string $userId): bool
    {
        if ($file->getClientOriginalExtension() === 'md') {
            return $this->importMarkdownFile($file, $userId);
        } else {
            return $this->importZipFile($file, $userId);
        }
    }

    private function importMarkdownFile(UploadedFile $file, string $userId, ?int $categoryId = null): bool
    {
        $fileName = Str::replace('.md', '', $file->getClientOriginalName());
        $contents = file_get_contents($file->getRealPath());
        $note = new Note([
            'title' => $fileName,
            'content' => $contents,
            'user_id' => $userId,
        ]);

        if ($categoryId) {
            $note->fill(['category_id' => $categoryId]);
        }

        return $note->save();
    }

    private function importZipFile(UploadedFile $file, string $userId): bool
    {
        $zipArchive = new \ZipArchive();
        if ($zipArchive->open($file->getRealPath()) !== true) {
            return false;
        }

        $processed = [];

        for ($i = 0; $i < $zipArchive->numFiles; $i++) {
            $stat = $zipArchive->statIndex($i);
            $this->processZipEntry($zipArchive, $stat['name'], $userId, null, $processed);
        }

        $zipArchive->close();
        return true;
    }

    private function processZipEntry(\ZipArchive $zip, string $path, string $userId, ?int $parentCategoryId = null, &$processed): void
    {
        Log::info('Processing entry: ' . $path);
        $isDirectory = str_ends_with($path, '/');
        $parentPath = substr($path, 0, strrpos($path, '/', -2) + 1);

        Log::info('Parent path: ' . $parentPath);

        if (isset($processed[$parentPath])) {
            $parentCategoryId = $processed[$parentPath];
        }

        if ($isDirectory) {
            $this->processDir($zip, $path, $userId, $parentCategoryId, $processed);
        } else {
            $this->processFile($zip, $path, $userId, $parentCategoryId);
        }
    }

    private function processDir(\ZipArchive $zip, string $path, string $userId, ?int $parentCategoryId = null, &$processed)
    {
        $dirname = rtrim($path, '/');

        if ($dirname !== '.') {
            Log::info('Processing directory: ' . $dirname . ' with parent category ID: ' . $parentCategoryId);
            $category = new NoteCategory([
                'title'      => basename($dirname),
                'user_id'   => $userId,
                'parent_id' => $parentCategoryId,
            ]);
            $category->save();
            $processed[$path] = $category->id;
            Log::info('Processed path: ' . $path . ' with Category ID ' . $category->id);
        }
    }

    private function processFile(\ZipArchive $zip, string $path, string $userId, ?int $parentCategoryId = null): void
    {
        Log::info('Processing note ' . $path . ' with parent category ID: ' . $parentCategoryId);
        $contents = $zip->getFromName($path);
        $note     = new Note([
            'title'       => str_replace('.md', '', basename($path)),
            'content'     => $contents,
            'user_id'     => $userId,
            'category_id' => $parentCategoryId,
        ]);
        $note->save();
    }
}
