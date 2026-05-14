<?php

namespace App\Api\Export\Services;

use App\Api\Libraries\Models\LibraryGame;
use App\Api\Users\Models\User;

class GameExportService
{
    public function export(User $user, string $path): void
    {
        $games = LibraryGame::forUser($user->id)->with(['tags', 'genres'])->get();

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Title', 'Rating', 'Publication Year', 'Platform', 'Developer',
            'Publisher', 'Playtime Hours', 'Completed', 'Wishlist',
            'Started At', 'Finished At', 'Genres', 'Tags',
        ]);

        foreach ($games as $game) {
            fputcsv($handle, [
                $game->title,
                $game->rating,
                $game->publication_year,
                $game->platform,
                $game->developer,
                $game->publisher,
                $game->playtime_hours,
                $game->completed ? 'Yes' : 'No',
                $game->wishlist ? 'Yes' : 'No',
                $game->started_at?->format('Y-m-d'),
                $game->finished_at?->format('Y-m-d'),
                $game->genres->pluck('title')->implode(', '),
                $game->tags->pluck('name')->implode(', '),
            ]);
        }

        fclose($handle);
    }
}
