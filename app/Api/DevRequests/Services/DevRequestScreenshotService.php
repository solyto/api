<?php

namespace App\Api\DevRequests\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DevRequestScreenshotService
{
    private const array EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function load(int $devRequestId, string $fileName): string | false
    {
        if (!Storage::disk('public')->exists($this->path($devRequestId, $fileName))) {
            return false;
        }

        return Storage::disk('public')->get($this->path($devRequestId, $fileName));
    }

    /**
     * @return array{bytes: string, extension: string}
     *
     * @throws ValidationException
     */
    public function decode(string $dataUri): array
    {
        if (preg_match('/^data:image\/(png|jpeg|gif|webp);base64,/', $dataUri, $matches) !== 1) {
            throw $this->invalid();
        }

        // The extension comes from the decoded bytes, never from the client.
        $bytes = base64_decode(substr($dataUri, strlen($matches[0])), true);
        // Bytes that are no image make getimagesizefromstring warn as well as return false; false is all we need.
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);

        if ($info === false || !isset(self::EXTENSIONS[$info['mime']])) {
            throw $this->invalid();
        }

        return ['bytes' => $bytes, 'extension' => self::EXTENSIONS[$info['mime']]];
    }

    public function save(int $devRequestId, string $bytes, string $extension): string | false
    {
        $fileName = 'screenshot.' . $extension;

        return Storage::disk('public')->put($this->path($devRequestId, $fileName), $bytes)
            ? $fileName
            : false;
    }

    private function path(int $devRequestId, string $fileName): string
    {
        return 'dev-requests/' . $devRequestId . '/' . $fileName;
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['screenshot' => 'The screenshot must be a PNG, JPEG, GIF or WebP image.']);
    }
}
