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
    use Queueable, Dispatchable, InteractsWithQueue;

    private const string CACHE_KEY_RELEASES = 'book_releases';
    private const string CACHE_KEY_LAST_NOTIFICATION = 'book_release_last_notification';
    private const int CACHE_TTL_RELEASES = 604800;

    public function handle(UserCacheService $cache): void
    {
        Log::channel('queue')->info('Grabbing new book releases for all users..');

        $users = User::all();

        foreach ($users as $user) {
            $service = app()->makeWith(LibraryReleases::class, ['user' => $user]);
            $releases = $service->getBookReleases();
            $cache->store([self::CACHE_KEY_RELEASES, $user->id], self::CACHE_TTL_RELEASES, $releases);

            $lastNotification = $cache->get([self::CACHE_KEY_LAST_NOTIFICATION, $user->id]);

            if ($lastNotification) {
                foreach ($releases as $release) {
                    if ($release->getReleaseDate() > $lastNotification) {
                        $user->notify(new BookReleaseNotification(
                            author: $release->getAuthor(),
                            title: $release->getTitle()
                        ));
                    }
                }
            }

            $cache->store([self::CACHE_KEY_LAST_NOTIFICATION, $user->id], self::CACHE_TTL_RELEASES, now());

            Log::channel('queue')->info('Cached ' . count($releases) . ' book releases for user ' . $user->id);
        }

        Log::channel('queue')->info('Done.');
    }
}
