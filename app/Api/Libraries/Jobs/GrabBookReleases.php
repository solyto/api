<?php

namespace App\Api\Libraries\Jobs;

use App\Api\Libraries\Notifications\BookReleaseNotification;
use App\Api\Libraries\Services\LibraryReleases;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class GrabBookReleases implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const string CACHE_KEY_RELEASES = 'book_releases';

    private const string CACHE_KEY_NOTIFIED = 'book_release_notified';

    private const int CACHE_TTL_RELEASES = 604800;

    public function handle(UserCacheService $cache): void
    {
        Log::channel('queue')->info('Grabbing new book releases for all users..');

        // The notified-set lives in the long-term cache store so it survives
        // deploys (the ephemeral cache DB may be flushed on deployment).
        $notifiedCache = new UserCacheService('longterm');

        $users = User::all();

        foreach ($users as $user) {
            $service = app()->makeWith(LibraryReleases::class, ['user' => $user]);
            $releases = $service->getBookReleases();
            $cache->store([self::CACHE_KEY_RELEASES, $user->id], self::CACHE_TTL_RELEASES, $releases);

            $notified = $notifiedCache->get([self::CACHE_KEY_NOTIFIED, $user->id]) ?? [];

            foreach ($releases as $release) {
                $id = $release->getId();

                if (in_array($id, $notified, true)) {
                    continue;
                }

                $user->notify(new BookReleaseNotification(
                    author: $release->getAuthor(),
                    title: $release->getTitle()
                ));

                $notified[] = $id;
            }

            $notifiedCache->store([self::CACHE_KEY_NOTIFIED, $user->id], 0, $notified);

            Log::channel('queue')->info('Cached '.count($releases).' book releases for user '.$user->id);
        }

        Log::channel('queue')->info('Done.');
    }
}
