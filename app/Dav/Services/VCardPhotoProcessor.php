<?php

namespace App\Dav\Services;

use App\Api\Contacts\Services\ContactService;
use App\Shared\Services\Images\ImageTransformationService;
use Illuminate\Support\Facades\File;

class VCardPhotoProcessor
{
    public function __construct(private readonly ImageTransformationService $imageTransformation) {}

    public function process(string $vCard): string
    {
        try {
            if (!preg_match('/(^PHOTO[^\r\n]*(?:\r?\n[ \t][^\r\n]*)*)/m', $vCard, $matches, PREG_OFFSET_CAPTURE)) {
                return $vCard;
            }

            $photoBlock  = $matches[1][0];
            $photoOffset = $matches[1][1];
            $colonPos    = strpos($photoBlock, ':');

            if ($colonPos === false) {
                return $vCard;
            }

            $meta         = substr($photoBlock, 0, $colonPos);
            $base64Folded = substr($photoBlock, $colonPos + 1);

            if (stripos($meta, 'VALUE=URI') !== false) {
                return $vCard;
            }

            $base64  = preg_replace('/\r?\n[ \t]/', '', $base64Folded);
            $tmpPath = $this->imageTransformation->base64ToTemp(trim($base64));

            try {
                if (!$this->imageTransformation->scaleToFileSize($tmpPath, ContactService::PHOTO_MAX_BYTES)) {
                    return $vCard;
                }

                $scaledBase64 = $this->imageTransformation->tempToBase64($tmpPath);
            } finally {
                File::delete($tmpPath);
            }

            preg_match('/TYPE=([A-Z0-9]+)/i', $meta, $typeMatch);
            $type   = strtoupper($typeMatch[1] ?? 'JPEG');
            $prefix = "PHOTO;ENCODING=b;TYPE={$type}:";

            $firstChunk    = substr($scaledBase64, 0, 75 - strlen($prefix));
            $rest          = substr($scaledBase64, strlen($firstChunk));
            $newPhotoBlock = $prefix . $firstChunk;

            foreach (str_split($rest, 74) as $chunk) {
                $newPhotoBlock .= "\r\n " . $chunk;
            }

            return substr($vCard, 0, $photoOffset) . $newPhotoBlock . substr($vCard, $photoOffset + strlen($photoBlock));
        } catch (\Throwable) {
            return $vCard;
        }
    }
}
