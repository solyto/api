<?php

namespace App\Api\DevRequests\Services;

use Illuminate\Support\Facades\Storage;

class DevRequestScreenshotService
{
    public function getFileName(string $fileName): string | false
    {
        $split = explode('.', $fileName);
        $extensiuon = end($split);

        return 'screenshot.' . $extensiuon;
    }

    public function load(int $devRequestId, string $fileName): string | false
    {
        if (!Storage::disk('public')->exists('dev-requests/' . $devRequestId . '/' . $fileName)) {
            return false;
        }

        return Storage::disk('public')->get('dev-requests/' . $devRequestId . '/' . $fileName);
    }

    public function save(int $devRequestId, string $fileName, string $file): string | false
    {
        if (!$this->createDir('dev-requests/' . $devRequestId)) {
            return false;
        }

        $store = Storage::disk('public')->putFileAs('dev-requests/' . $devRequestId, $file, $this->getFileName($fileName));

        return $store ?
            'dev-requests/ ' . $devRequestId . '/' . $this->getFileName($fileName) :
            false;
    }

    private function createDir(string $dir): bool
    {
        if (!Storage::disk('public')->exists($dir)) {
            if (!Storage::disk('public')->makeDirectory($dir)) {
                return false;
            }
        }

        return true;
    }

}
