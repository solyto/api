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
    use Dispatchable, InteractsWithQueue, Queueable;

    private const string CACHE_KEY_RELEASES = 'movie_releases';

    private const string CACHE_KEY_NOTIFIED = 'movie_release_notified';

    private const int CACHE_TTL_RELEASES = 604800;

    public function handle(UserCacheService $cache): void
    {
        Log::channel('queue')->info('Grabbing new movie releases for all users..');

        // The notified-set lives in the long-term cache store so it survives
        // deploys (the ephemeral cache DB may be flushed on deployment).
        $notifiedCache = new UserCacheService('longterm');

        $users = User::all();

        foreach ($users as $user) {
            $service = app()->makeWith(LibraryReleases::class, ['user' => $user]);
            $releases = $service->getMovieReleases();
            $cache->store([self::CACHE_KEY_RELEASES, $user->id], self::CACHE_TTL_RELEASES, $releases);

            $notified = $notifiedCache->get([self::CACHE_KEY_NOTIFIED, $user->id]) ?? [];

            foreach ($releases as $release) {
                $id = $release->getId();

                if (in_array($id, $notified, true)) {
                    continue;
                }

                $user->notify(new MovieReleaseNotification(
                    title: $release->getTitle(),
                    type: $release->getType(),
                    releaseDate: '',
                ));

                $notified[] = $id;
            }

            $notifiedCache->store([self::CACHE_KEY_NOTIFIED, $user->id], 0, $notified);

            Log::channel('queue')->info('Cached '.count($releases).' movie releases for user '.$user->id);
        }

        Log::channel('queue')->info('Done.');
    }
}
