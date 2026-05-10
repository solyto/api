<?php

namespace App\Api\QuickAdd\Services;

class QuickAddService
{
    public function detect(string $url): array
    {
        // TODO: Implement your detection logic here.
        // The method should return an array with:
        //   - content_type: string (one of: music, books, movies, games, links, recipes, plants, quotes, todo, note, feed)
        //   - confidence: float (0.0 - 1.0)
        //   - metadata: array|null (any extracted metadata like title, artist, etc.)

        return [
            'url' => $url,
            'content_type' => 'links',
            'confidence' => 0.3,
            'metadata' => null,
        ];
    }
}
