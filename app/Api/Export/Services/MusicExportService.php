<?php

namespace App\Api\Export\Services;

use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Users\Models\User;

class MusicExportService
{
    public function export(User $user, string $path): void
    {
        $items = LibraryMusic::forUser($user->id)->with('genres')->get();

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Title', 'Artist', 'Type', 'Format', 'Condition', 'Rating',
            'Publication Year', 'Acquired Where', 'Genres', 'Wishlist', 'Link',
        ]);

        foreach ($items as $item) {
            fputcsv($handle, [
                $item->title,
                $item->artist,
                $item->type,
                $item->format,
                $item->condition,
                $item->rating,
                $item->publication_year,
                $item->acquired_where,
                $item->genres->pluck('title')->implode(', '),
                $item->wishlist ? 'Yes' : 'No',
                $item->link,
            ]);
        }

        fclose($handle);
    }
}
