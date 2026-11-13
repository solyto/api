<?php

use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function sharedFlag(int $calendarId): bool
{
    return (bool) DB::connection('pgsql')->table('calendarinstances')
        ->where('calendarid', $calendarId)
        ->where('access', 1)
        ->value('solyto_shared');
}

function inviteToCalendar(CalendarDTO $calendar, User $owner, User $sharee, bool $accept = true): void
{
    $sharing = app(DavService::class)->calendars()->sharing();
    $sharing->inviteUser($calendar, $owner, $sharee);

    if ($accept) {
        $sharing->acceptInvite($sharee, 'mailto:'.$sharee->email);
    }
}

function shareeInstanceOf(User $sharee): CalendarDTO
{
    return collect(app(DavService::class)->calendars()->list($sharee))->firstOrFail(fn (CalendarDTO $c) => $c->isShared);
}

describe('solyto_shared lifecycle', function () {
    beforeEach(function () {
        $this->owner = makeUser();
        $this->sharee = makeUser();
        $this->calendar = app(DavService::class)->calendars()->list($this->owner)[0];
    });

    it('stays false while the invite is pending', function () {
        inviteToCalendar($this->calendar, $this->owner, $this->sharee, accept: false);

        expect(sharedFlag($this->calendar->calendarId))->toBeFalse();
    });

    it('turns true on accept, on the owner row only', function () {
        inviteToCalendar($this->calendar, $this->owner, $this->sharee);

        expect(sharedFlag($this->calendar->calendarId))->toBeTrue()
            ->and(DB::connection('pgsql')->table('calendarinstances')
                ->where('calendarid', $this->calendar->calendarId)
                ->where('access', '>', 1)
                ->value('solyto_shared'))->toBeFalsy();
    });

    it('stays true until the last sharee is revoked', function () {
        $second = makeUser();
        inviteToCalendar($this->calendar, $this->owner, $this->sharee);
        inviteToCalendar($this->calendar, $this->owner, $second);
        $sharing = app(DavService::class)->calendars()->sharing();

        $sharing->revokeShare($this->calendar->calendarId, $this->sharee);
        expect(sharedFlag($this->calendar->calendarId))->toBeTrue();

        $sharing->revokeShare($this->calendar->calendarId, $second);
        expect(sharedFlag($this->calendar->calendarId))->toBeFalse();
    });

    it('turns false when the sharee unsubscribes', function () {
        inviteToCalendar($this->calendar, $this->owner, $this->sharee);

        app(DavService::class)->calendars()->delete(shareeInstanceOf($this->sharee));

        expect(sharedFlag($this->calendar->calendarId))->toBeFalse();
    });

    it('turns false when the sharee\'s account is deleted', function () {
        inviteToCalendar($this->calendar, $this->owner, $this->sharee);

        app(DavService::class)->deleteForUser($this->sharee);

        expect(sharedFlag($this->calendar->calendarId))->toBeFalse();
    });

    it('carries the flag on the owner\'s CalendarDTO', function () {
        $calendars = app(DavService::class)->calendars();
        inviteToCalendar($this->calendar, $this->owner, $this->sharee);

        expect($calendars->list($this->owner)[0]->solytoShared)->toBeTrue()
            ->and($calendars->get($this->owner, $this->calendar->instanceId)->solytoShared)->toBeTrue()
            ->and($calendars->getByName($this->owner, $this->calendar->displayName)->solytoShared)->toBeTrue()
            ->and(shareeInstanceOf($this->sharee)->solytoShared)->toBeFalse();
    });
});

describe('solyto_shared migration', function () {
    it('backfills the owner row of calendars with an accepted sharee', function () {
        $calendars = app(DavService::class)->calendars();
        [$owner, $sharee, $pendingOwner, $pendingSharee] = [makeUser(), makeUser(), makeUser(), makeUser()];
        $accepted = $calendars->list($owner)[0];
        $pending = $calendars->list($pendingOwner)[0];
        inviteToCalendar($accepted, $owner, $sharee);
        inviteToCalendar($pending, $pendingOwner, $pendingSharee, accept: false);

        $migration = require database_path('migrations/2026_11_13_000001_add_solyto_shared_to_calendarinstances.php');
        $migration->down();
        $migration->up();

        expect(sharedFlag($accepted->calendarId))->toBeTrue()
            ->and(sharedFlag($pending->calendarId))->toBeFalse()
            ->and(DB::connection('pgsql')->table('calendarinstances')
                ->where('access', '>', 1)
                ->where('solyto_shared', true)
                ->count())->toBe(0);
    });
});
