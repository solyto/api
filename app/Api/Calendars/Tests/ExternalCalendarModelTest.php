<?php

use App\Api\Calendars\Models\ExternalCalendar;
use App\Api\Calendars\Models\ExternalCalendarAccount;
use Illuminate\Support\Facades\DB;

describe('External calendar models', function () {
    it('stores the app password encrypted and hides it from serialisation', function () {
        $account = ExternalCalendarAccount::factory()->forUser(makeUser())->create(['password' => 'app-secret']);

        expect(DB::table('external_calendar_accounts')->value('password'))->not->toContain('app-secret')
            ->and($account->fresh()->password)->toBe('app-secret')
            ->and($account->toArray())->not->toHaveKey('password');
    });

    it('scopes both models to their user', function () {
        $user = makeUser();
        $account = ExternalCalendarAccount::factory()->forUser($user)->create();
        ExternalCalendar::factory()->forAccount($account)->create();
        ExternalCalendar::factory()->create();

        expect(ExternalCalendarAccount::forUser($user->id)->count())->toBe(1)
            ->and(ExternalCalendar::forUser($user->id)->count())->toBe(1);
    });
});
