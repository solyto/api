<?php

use App\Dav\Auth\LaravelAuthBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('DAV authentication', function () {
    it('challenges a request without credentials with Basic auth', function () {
        $response = davRequest(null, 'PROPFIND', '/', null, ['Depth' => '0']);

        expect($response->getStatus())->toBe(401)
            ->and($response->getHeader('WWW-Authenticate'))->toStartWith('Basic ');
    });

    it('rejects a wrong password', function () {
        $user = davUser();

        expect(davRequest($user, 'PROPFIND', '/', null, ['Depth' => '0'], 'wrong-password')->getStatus())->toBe(401);
    });

    it('rejects an unknown user', function () {
        $user = davUser();
        $user->email = 'nobody@example.org';

        expect(davRequest($user, 'PROPFIND', '/', null, ['Depth' => '0'])->getStatus())->toBe(401);
    });

    it('accepts the account email and password', function () {
        $user = davUser();

        expect(davRequest($user, 'PROPFIND', '/', null, ['Depth' => '0'])->getStatus())->toBe(207);
    });

    it('challenges OPTIONS without credentials too', function () {
        expect(davRequest(null, 'OPTIONS', '/')->getStatus())->toBe(401);
    });

    it('sets the authenticated user on the Laravel guard', function () {
        $user = davUser();

        davRequest($user, 'PROPFIND', '/', null, ['Depth' => '0']);

        expect(auth()->user()?->is($user))->toBeTrue();
    });
});

describe('LaravelAuthBackend', function () {
    it('validates the email and password against the users table', function () {
        $user = makeUser();
        $backend = new class extends LaravelAuthBackend
        {
            public function validate(string $username, string $password): bool
            {
                return $this->validateUserPass($username, $password);
            }
        };

        expect($backend->validate($user->email, 'password'))->toBeTrue()
            ->and($backend->validate($user->email, 'nope'))->toBeFalse()
            ->and($backend->validate('missing@example.org', 'password'))->toBeFalse();
    });
});

describe('DAV discovery', function () {
    it('reports the current user principal at the root', function () {
        $user = davUser();

        $response = davRequest($user, 'PROPFIND', '/', davPropfindBody(['d:current-user-principal']), ['Depth' => '0']);

        expect($response->getStatus())->toBe(207)
            ->and(davXpath($response)->evaluate('string(//d:current-user-principal/d:href)'))->toBe('/principals/'.$user->email.'/');
    });

    it('reports the calendar and address book home sets on the principal', function () {
        $user = davUser();

        $response = davRequest($user, 'PROPFIND', 'principals/'.$user->email.'/', davPropfindBody([
            'cal:calendar-home-set', 'card:addressbook-home-set', 's:email-address',
        ]), ['Depth' => '0']);
        $xpath = davXpath($response);

        expect($response->getStatus())->toBe(207)
            ->and($xpath->evaluate('string(//cal:calendar-home-set/d:href)'))->toBe('/calendars/'.$user->email.'/')
            ->and($xpath->evaluate('string(//card:addressbook-home-set/d:href)'))->toBe('/addressbooks/'.$user->email.'/')
            ->and($xpath->evaluate('string(//s:email-address)'))->toBe($user->email);
    });

    it('lists the default calendar in the calendar home', function () {
        $user = davUser();

        $response = davRequest($user, 'PROPFIND', 'calendars/'.$user->email.'/', davPropfindBody(['d:displayname', 'd:resourcetype']), ['Depth' => '1']);
        $xpath = davXpath($response);

        expect($xpath->evaluate('string(//d:response[d:propstat/d:prop/d:resourcetype/cal:calendar]/d:href)'))
            ->toBe('/calendars/'.$user->email.'/my-calendar/')
            ->and($xpath->evaluate('string(//d:response[d:propstat/d:prop/d:resourcetype/cal:calendar]//d:displayname)'))
            ->toBe('My Calendar');
    });

    it('lists the default address book in the address book home', function () {
        $user = davUser();

        $response = davRequest($user, 'PROPFIND', 'addressbooks/'.$user->email.'/', davPropfindBody(['d:displayname', 'd:resourcetype']), ['Depth' => '1']);
        $xpath = davXpath($response);

        expect($xpath->evaluate('string(//d:response[d:propstat/d:prop/d:resourcetype/card:addressbook]/d:href)'))
            ->toBe('/addressbooks/'.$user->email.'/contacts/')
            ->and($xpath->evaluate('string(//d:response[d:propstat/d:prop/d:resourcetype/card:addressbook]//d:displayname)'))
            ->toBe('Contacts');
    });

    it('advertises calendar and address book support in the DAV header', function () {
        $user = davUser();

        $dav = davRequest($user, 'OPTIONS', 'calendars/'.$user->email.'/')->getHeader('DAV');

        expect($dav)->toContain('calendar-access')
            ->and($dav)->toContain('addressbook')
            ->and($dav)->toContain('resource-sharing');
    });
});
