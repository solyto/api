<?php

namespace App\Api\Users\Services;

use App\Api\DevRequests\Models\DevRequest;
use App\Api\Users\Models\User;
use App\Api\Users\Models\VerificationToken;
use App\Dav\Services\DavService;
use App\Shared\Services\UserCacheService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserService
{
    /**
     * Per-user cache keys of the form [key, user id] held by the module
     * services on the user_data store. weather_forecast (keyed by location)
     * and feed_items (keyed by feed id) are shared and deliberately absent;
     * the *_release_notified keys live in the longterm store instead (see
     * USER_LONGTERM_CACHE_KEYS).
     */
    private const array USER_CACHE_KEYS = [
        'address_books', 'book_recommendations', 'book_releases', 'books', 'budgets',
        'calendars', 'checkin', 'clipboard', 'games', 'links', 'movie_releases', 'movies',
        'music', 'music_recommendations', 'music_releases', 'note_categories',
        'note_categories_hidden', 'notes', 'notes_hidden', 'notes_newest', 'plants', 'quotes',
        'recipes', 'shortcuts', 'tags', 'todo_categories', 'todo_workspaces', 'todos',
        'todos_due', 'tt_categories', 'tt_entries', 'tt_projects', 'user_feeds', 'wealth_fields',
    ];

    /**
     * Per-user cache keys of the form [key, user id] on the longterm store:
     * the Grab*Releases jobs keep the release-notified sets there forever so
     * they survive deploys — forgetting them through the user_data store
     * would be a silent no-op.
     */
    private const array USER_LONGTERM_CACHE_KEYS = [
        'book_release_notified', 'movie_release_notified', 'music_release_notified',
    ];

    /**
     * Per-user cache keys of the form [key, user id, resource id] — deleted
     * by prefix, one key per resource the user owned.
     */
    private const array USER_CACHE_PREFIX_KEYS = [
        'calendar_events', 'contacts', 'event_attachment_notes', 'event_attachment_todos',
    ];

    public function __construct(
        private readonly DavService $davService,
        private readonly UserCacheService $cache,
        private readonly UserProfileImageService $profileImageService
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return User::query()->latest()->paginate($perPage);
    }

    public function find(User $user): User
    {
        return $user;
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);

        return $user;
    }

    /**
     * Removes everything the user owns: DAV data on pgsql, the morph rows
     * the users cascade does not cover, the user row (FK cascades take the
     * rest), both per-user storage directories and the user-scoped cache
     * keys. DAV goes first and the user row inside the transaction last, so
     * an aborted run leaves the user row intact and the routine re-runnable.
     *
     * @return array<string, int|bool>
     */
    public function destroy(User $user): array
    {
        $dav = $this->davService->deleteForUser($user);

        $app = DB::transaction(function () use ($user): array {
            // dev_requests is shared community data (STANDARDS 12.3) and its
            // user FK has no onDelete cascade: the attribution is anonymised
            // so the requests (with other users' comments and votes) survive.
            $devRequests = DevRequest::where('created_by_user_id', $user->id)
                ->update(['created_by_user_id' => null]);

            $tokens = $user->tokens()->delete();
            $notifications = $user->notifications()->delete();
            $pushSubscriptions = $user->pushSubscriptions()->delete();
            $verificationTokens = VerificationToken::where('user_id', $user->id)->delete();

            // Both tables have no FK to users and no cascade: the reset
            // tokens are keyed by the user's email, the session rows by
            // user_id (rows exist only with the database session driver).
            $passwordResetTokens = DB::table('password_reset_tokens')
                ->where('email', $user->email)->delete();
            $sessions = DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->delete();

            return [
                'dev_requests_anonymised' => $devRequests,
                'auth_tokens' => $tokens,
                'notifications' => $notifications,
                'push_subscriptions' => $pushSubscriptions,
                'verification_tokens' => $verificationTokens,
                'password_reset_tokens' => $passwordResetTokens,
                'sessions' => $sessions,
            ];
        });

        // deleteDirectory reports true even for a missing directory, so the
        // summary says whether a per-user directory existed.
        $userData = Storage::disk('user_data');
        $privateUserData = Storage::disk('private_user_data');
        $files = [
            'user_data_files' => $userData->exists($user->id),
            'private_user_data_files' => $privateUserData->exists($user->id),
        ];
        $userData->deleteDirectory($user->id);
        $privateUserData->deleteDirectory($user->id);

        $cacheKeys = $this->forgetUserCache($user);

        return [...$dav, ...$app, ...$files, 'cache_keys_flushed' => $cacheKeys];
    }

    public function changePassword(User $user, string $newPassword): void
    {
        $user->update(['password' => Hash::make($newPassword)]);
        $user->tokens()->delete();
    }

    public function me(User $user): User
    {
        $user->load(['profile', 'settings']);

        return $user;
    }

    public function updateProfileImage(User $user, UploadedFile $file): bool
    {
        $filePath = $this->profileImageService->save($user->id, $file);

        if (!$filePath) {
            return false;
        }

        $user->profile()->update(['profile_image_path' => $filePath]);

        return true;
    }

    public function publicProfile(User $user): User
    {
        return $user;
    }

    private function forgetUserCache(User $user): int
    {
        $longterm = new UserCacheService('longterm');

        foreach (self::USER_LONGTERM_CACHE_KEYS as $key) {
            $longterm->forget([$key, $user->id]);
        }

        foreach (self::USER_CACHE_KEYS as $key) {
            $this->cache->forget([$key, $user->id]);
        }

        foreach (self::USER_CACHE_PREFIX_KEYS as $key) {
            $this->cache->forgetByPrefix([$key, $user->id]);
        }

        return count(self::USER_LONGTERM_CACHE_KEYS) + count(self::USER_CACHE_KEYS) + count(self::USER_CACHE_PREFIX_KEYS);
    }
}
