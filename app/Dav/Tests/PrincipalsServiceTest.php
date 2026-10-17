<?php

use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('Principals', function () {
    it('creates a principal that carries the email address', function () {
        $principals = app(DavService::class)->principals();

        $uri = $principals->create('jane@example.org');

        expect($uri)->toBe('principals/jane@example.org')
            ->and($principals->get($uri)['{http://sabredav.org/ns}email-address'])->toBe('jane@example.org')
            ->and(collect($principals->list())->pluck('uri'))->toContain($uri);
    });

    it('refuses to create the same principal twice', function () {
        $principals = app(DavService::class)->principals();
        $principals->create('jane@example.org');

        $principals->create('jane@example.org');
    })->throws(\RuntimeException::class, 'Principal already exists');

    it('answers an unknown principal with null', function () {
        expect(app(DavService::class)->principals()->get('principals/nobody@example.org'))->toBeNull();
    });

    it('deletes the principal, its proxies and its group memberships', function () {
        $principals = app(DavService::class)->principals();
        $principals->create('jane@example.org');
        $principals->create('john@example.org');
        $pgsql = DB::connection('pgsql');
        $pgsql->table('principals')->insert(['uri' => 'principals/jane@example.org/calendar-proxy-read']);
        $janeProxy = $pgsql->table('principals')->where('uri', 'principals/jane@example.org/calendar-proxy-read')->value('id');
        $john = $pgsql->table('principals')->where('uri', 'principals/john@example.org')->value('id');
        $pgsql->table('groupmembers')->insert(['principal_id' => $janeProxy, 'member_id' => $john]);

        $deleted = $principals->delete('jane@example.org');

        expect($deleted)->toBeTrue()
            ->and($pgsql->table('principals')->where('uri', 'like', 'principals/jane@example.org%')->count())->toBe(0)
            ->and($pgsql->table('groupmembers')->count())->toBe(0)
            ->and($principals->get('principals/john@example.org'))->not->toBeNull();
    });

    it('answers deleting an unknown principal with false', function () {
        expect(app(DavService::class)->principals()->delete('nobody@example.org'))->toBeFalse();
    });

    it('removes the user\'s DAV home once their principal is deleted', function () {
        $user = davUser();

        app(DavService::class)->principals()->delete($user->email);

        expect(davRequest($user, 'PROPFIND', 'calendars/'.$user->email.'/', null, ['Depth' => '0'])->getStatus())->toBe(404);
    });
});
