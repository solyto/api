<?php

namespace App\Api\Libraries\Jobs;

use App\Api\Libraries\Notifications\MovieReleaseNotification;
use App\Api\Libraries\Services\LibraryReleases;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class GrabMovieReleases implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue;

    private const string CACHE_KEY_RELEASES = 'movie_releases';
    private const string CACHE_KEY_LAST_NOTIFICATION = 'movie_release_last_notification';
    private const int CACHE_TTL_RELEASES = 604800;

    public function handle(UserCacheService $cache): void
    {
        Log::channel('queue')->info('Grabbing new movie releases for all users..');

        $users = User::all();

        foreach ($users as $user) {
            $service = app()->makeWith(LibraryReleases::class, ['user' => $user]);
            $releases = $service->getMovieReleases();
            $cache->store([self::CACHE_KEY_RELEASES, $user->id], self::CACHE_TTL_RELEASES, $releases);

            $lastNotification = $cache->get([self::CACHE_KEY_LAST_NOTIFICATION, $user->id]);

            if ($lastNotification) {
                foreach ($releases as $release) {
                    if ($release->getReleaseDate() > $lastNotification) {
                        $user->notify(new MovieReleaseNotification(
                            title: $release->getTitle(),
                            type: $release->getType(),
                            releaseDate: $release->getReleaseDate()->format('Y-m-d'),
                        ));
                    }
                }
            }

            $cache->store([self::CACHE_KEY_LAST_NOTIFICATION, $user->id], self::CACHE_TTL_RELEASES, now());

            Log::channel('queue')->info('Cached ' . count($releases) . ' movie releases for user ' . $user->id);
        }

        Log::channel('queue')->info('Done.');
    }
}
