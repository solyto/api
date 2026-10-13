<?php

use App\Api\Contacts\Models\AddressBook;
use App\Api\Contacts\Models\Contact;
use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestComment;
use App\Api\DevRequests\Models\DevRequestVote;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Notes\Models\Note;
use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;
use App\Api\Users\Models\VerificationToken;
use App\Dav\DTOs\ContactDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Models\Principal;
use App\Dav\Services\DavService;
use App\Shared\Notifications\TestNotification;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use NotificationChannels\WebPush\PushSubscription;

describe('app:users:delete', function () {
    it('deletes the user and all their data', function () {
        $user = makeUser(['email' => 'deleteme@example.com', 'name' => 'Delete Me']);
        $other = makeUser();

        $todo = Todo::factory()->forUser($user)->create();
        Note::factory()->forUser($user)->create();
        $appAddressBook = AddressBook::factory()->forUser($user)->create();
        Contact::factory()->forUser($user)->forAddressBook($appAddressBook)->create();
        FeedSubscription::factory()->forUser($user)->create();
        TelegramBotConnection::factory()->forUser($user)->create();
        $devRequest = DevRequest::factory()->create(['created_by_user_id' => $user->id]);
        DevRequestVote::factory()->forDevRequestAndUser($devRequest, $other)->create();
        DevRequestComment::factory()->forDevRequestAndUser($devRequest, $other)->create();

        $user->notify(new TestNotification);
        $user->createToken('api');
        $user->updatePushSubscription('https://push.example.com/sub', 'base64key', 'base64auth', 'aes128gcm');
        VerificationToken::factory()->forUser($user)->create();
        Password::broker()->createToken($user);
        Password::broker()->createToken($other);
        DB::table('sessions')->insert([
            'id' => 'deleted-user-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'pest',
            'payload' => 'serialized-session',
            'last_activity' => time(),
        ]);

        $dav = app(DavService::class);
        $dav->principals()->create($user->email);
        $principalUri = 'principals/'.$user->email;
        $proxyUri = $principalUri.'/calendar-proxy-read';
        Principal::create(['uri' => $proxyUri, 'email' => null, 'displayname' => null]);

        $calendar = $dav->calendars()->list($user)[0];
        $dav->calendars()->events()->create($calendar, EventDTO::fromRequest([
            'title' => 'Final meeting',
            'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_date' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            'is_all_day' => false,
        ], $calendar, $user));

        $addressBook = $dav->addressBooks()->list($user)[0];
        $dav->addressBooks()->contacts()->create($addressBook, new ContactDTO(
            uid: null,
            firstName: 'John',
            middleName: null,
            lastName: 'Doe',
            fullName: 'John Doe',
            email: null,
            phone: null,
            organization: null,
            street: null,
            city: null,
            state: null,
            postalCode: null,
            country: null,
            photo: null,
            groups: null,
            prefix: null,
            suffix: null,
            note: null,
            uri: null,
            etag: null,
            addressBookId: null,
        ));

        $principalId = Principal::where('uri', $principalUri)->value('id');
        $proxyId = Principal::where('uri', $proxyUri)->value('id');
        $dav->principals()->create($other->email);
        $otherPrincipalId = Principal::where('uri', 'principals/'.$other->email)->value('id');
        // one row per FK direction of the deleted proxy principal
        DB::connection('pgsql')->table('groupmembers')->insert([
            ['principal_id' => $proxyId, 'member_id' => $otherPrincipalId],
            ['principal_id' => $otherPrincipalId, 'member_id' => $proxyId],
        ]);

        $pgsql = DB::connection('pgsql');
        $pgsql->table('schedulingobjects')->insert([
            'principaluri' => $principalUri,
            'calendardata' => 'BEGIN:VCALENDAR',
            'uri' => 'sched.ics',
            'lastmodified' => time(),
            'etag' => '"abc"',
            'size' => 15,
        ]);
        $pgsql->table('calendarsubscriptions')->insert([
            'uri' => 'external.ics',
            'principaluri' => $principalUri,
            'source' => 'https://example.com/cal.ics',
            'displayname' => 'External',
            'calendarorder' => 0,
            'lastmodified' => time(),
        ]);
        $pgsql->table('propertystorage')->insert([
            'path' => 'calendars/'.$user->email.'/'.$calendar->uri,
            'name' => '{DAV:}displayname',
            'valuetype' => 1,
            'value' => 'My Calendar',
        ]);

        Storage::fake('user_data');
        Storage::fake('private_user_data');
        Storage::disk('user_data')->put($user->id.'/profile/avatar.jpg', 'bytes');
        Storage::disk('private_user_data')->put($user->id.'/contacts/'.$todo->id.'.jpg', 'bytes');

        $cache = app(UserCacheService::class);
        $cache->store(['todos', $user->id], 300, ['cached']);
        $cache->store(['books', $user->id], 300, ['cached']);
        $cache->store(['checkin', $user->id], 300, ['cached']);
        $cache->store(['todos', $other->id], 300, ['cached']);

        // The release-notified sets live forever in the longterm store (the
        // Grab*Releases jobs' own pattern), not in the user_data store.
        $longterm = new UserCacheService('longterm');
        $longterm->store(['book_release_notified', $user->id], 0, ['notified']);
        $longterm->store(['book_release_notified', $other->id], 0, ['notified']);

        $this->artisan('app:users:delete', ['email' => $user->email])
            ->expectsConfirmation('Permanently delete '.$user->name.' and ALL their data? This cannot be undone', 'yes')
            ->expectsOutputToContain('User '.$user->email.' deleted successfully.')
            ->assertSuccessful();

        expect(User::where('email', $user->email)->exists())->toBeFalse();
        expect($other->fresh())->not->toBeNull();

        expect(Todo::count())->toBe(0)
            ->and(Note::count())->toBe(0)
            ->and(AddressBook::count())->toBe(0)
            ->and(Contact::count())->toBe(0)
            ->and(FeedSubscription::count())->toBe(0)
            ->and(TelegramBotConnection::count())->toBe(0)
            ->and(VerificationToken::count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->count())->toBe(0)
            ->and(DB::table('notifications')->count())->toBe(0)
            ->and(DB::table('sessions')->count())->toBe(0)
            ->and(PushSubscription::count())->toBe(0);

        expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse()
            ->and(DB::table('password_reset_tokens')->where('email', $other->email)->exists())->toBeTrue();

        expect($devRequest->fresh()->created_by_user_id)->toBeNull()
            ->and(DevRequestVote::count())->toBe(1)
            ->and(DevRequestComment::count())->toBe(1);

        $pgsql = DB::connection('pgsql');
        expect($pgsql->table('principals')->where('uri', 'like', $principalUri.'%')->count())->toBe(0)
            ->and($pgsql->table('principals')->where('uri', 'principals/'.$other->email)->count())->toBe(1)
            ->and($pgsql->table('groupmembers')->count())->toBe(0)
            ->and($pgsql->table('calendarinstances')->where('principaluri', $principalUri)->count())->toBe(0)
            ->and($pgsql->table('calendarobjects')->where('calendarid', $calendar->calendarId)->count())->toBe(0)
            ->and($pgsql->table('calendars')->where('id', $calendar->calendarId)->count())->toBe(0)
            ->and($pgsql->table('addressbooks')->where('principaluri', $principalUri)->count())->toBe(0)
            ->and($pgsql->table('cards')->count())->toBe(0)
            ->and($pgsql->table('calendarsubscriptions')->where('principaluri', $principalUri)->count())->toBe(0)
            ->and($pgsql->table('schedulingobjects')->where('principaluri', $principalUri)->count())->toBe(0)
            ->and($pgsql->table('propertystorage')->where('path', 'like', 'calendars/'.$user->email.'%')->count())->toBe(0)
            ->and($pgsql->table('propertystorage')->where('path', 'like', 'addressbooks/'.$user->email.'%')->count())->toBe(0);

        Storage::disk('user_data')->assertMissing($user->id.'/profile/avatar.jpg');
        Storage::disk('private_user_data')->assertMissing($user->id.'/contacts/'.$todo->id.'.jpg');

        expect($cache->has(['todos', $user->id]))->toBeFalse()
            ->and($cache->has(['books', $user->id]))->toBeFalse()
            ->and($cache->has(['checkin', $user->id]))->toBeFalse()
            ->and($cache->has(['todos', $other->id]))->toBeTrue()
            ->and($longterm->has(['book_release_notified', $user->id]))->toBeFalse()
            ->and($longterm->has(['book_release_notified', $other->id]))->toBeTrue();
    });

    it('errors when the user is unknown', function () {
        makeUser(['email' => 'stays@example.com']);

        $this->artisan('app:users:delete', ['email' => 'unknown@example.com'])
            ->expectsOutputToContain('No user found with email: unknown@example.com')
            ->assertFailed();

        expect(User::count())->toBe(1);
    });

    it('deletes nothing when the confirmation is declined', function () {
        $user = makeUser(['email' => 'stays@example.com', 'name' => 'Stays']);
        $user->createToken('api');

        Storage::fake('user_data');
        Storage::disk('user_data')->put($user->id.'/profile/avatar.jpg', 'bytes');

        $this->artisan('app:users:delete', ['email' => $user->email])
            ->expectsConfirmation('Permanently delete '.$user->name.' and ALL their data? This cannot be undone', 'no')
            ->expectsOutputToContain('Deletion cancelled. Nothing was deleted.')
            ->assertSuccessful();

        expect($user->fresh())->not->toBeNull()
            ->and(DB::table('personal_access_tokens')->count())->toBe(1);

        $calendar = app(DavService::class)->calendars()->list($user)[0];
        expect($calendar)->not->toBeNull();

        Storage::disk('user_data')->assertExists($user->id.'/profile/avatar.jpg');
    });
});
