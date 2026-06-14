<?php

namespace App\Api\Libraries\Jobs;

use App\Api\Libraries\Services\LibraryReleases;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use App\Shared\Services\Images\ImageTransformationService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScaleCovers implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    private const string CACHE_KEY_RELEASES = 'music_releases';
    private const int CACHE_TTL_RELEASES = 86400;

    public function handle(UserCacheService $cache, ImageTransformationService $imageTransformation): void
    {
        Log::channel('queue')->info('Looking for covers that are too big..');

        $folders = File::directories(storage_path('app/public/user'));
        $dirs = ['music', 'movies', 'books', 'recipes'];

        foreach ($folders as $folder) {
            $folderName = Str::replace(storage_path('app/public/user/'), '', $folder);
            if (Str::length($folderName) === 36) {
                foreach ($dirs as $dir) {
                    if (!File::exists($folder . '/' . $dir)) {
                        continue;
                    }

                    $covers = File::files($folder . '/' . $dir);

                    foreach ($covers as $cover) {
                        if (Str::contains($cover->getFilename(), 'original')) {
                            continue;
                        }

                        $extension = $cover->getExtension();
                        $fileName = Str::replace('.' . $extension, '', $cover->getFilename());
                        $originalFilename = $fileName . '_original.' . $extension;
                        $originalPath = Str::replace($cover->getFilename(), $originalFilename, $cover->getPathname());

                        if (File::exists($originalPath)) {
                            continue;
                        }

                        File::copy($cover->getPathname(), $originalPath);

                        $imageTransformation->scaleToWidth($cover->getPathname(), 400, 85);

                        Log::channel('queue')->info('Processed ' . $cover->getPathname());
                    }
                }
            }
        }

        Log::channel('queue')->info('Done processing album covers.');

        $users = User::all();

        foreach ($users as $user) {
            $service = app()->makeWith(LibraryReleases::class, ['user' => $user]);
            $releases = $service->getMusicReleases();
            $cache->store([self::CACHE_KEY_RELEASES, $user->id], self::CACHE_TTL_RELEASES, $releases);

            Log::channel('queue')->info('Cached ' . count($releases) . ' releases for user ' . $user->id);
        }

        Log::channel('queue')->info('Done.');
    }
}
