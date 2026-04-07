<?php

namespace App\Api\Contacts\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="ContactPhoto",
 *
 *     @OA\Property(property="photo", type="string", format="uri", nullable=true)
 * )
 */
class ContactPhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'photo' => $this->convertImageString($this->photo),
        ];
    }

    private function convertImageString(?string $photo): ?string
    {
        if (empty($photo)) {
            return null;
        }

        [$meta, $base64Data] = explode(':', $photo, 2);

        $base64Data = preg_replace('/\s+/', '', $base64Data);

        $type = 'image/jpeg';
        if (preg_match('/TYPE=([A-Z0-9]+)/i', $meta, $matches)) {
            $type = 'image/'.strtolower($matches[1]);
        }

        return 'data:'.$type.';base64,'.$base64Data;
    }
}
