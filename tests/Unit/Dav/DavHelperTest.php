<?php

use App\Api\Users\Models\User;
use App\Dav\Helpers\DavHelper;
use App\Dav\Helpers\UrlHelper;

describe('Dav UrlHelper', function () {
    it('extracts the scheme', function () {
        expect(UrlHelper::getScheme('https://example.com/dav'))->toBe('https');
        expect(UrlHelper::getScheme('http://example.com'))->toBe('http');
    });

    it('returns null for an invalid url', function () {
        expect(UrlHelper::getScheme('not a url'))->toBeNull();
    });

    it('extracts the host', function () {
        expect(UrlHelper::getHost('https://dav.example.com:8080/cal'))->toBe('dav.example.com');
    });

    it('returns null for an invalid host', function () {
        expect(UrlHelper::getHost('not a url'))->toBeNull();
    });

    it('builds the base url from scheme and host', function () {
        expect(UrlHelper::getBaseUrl('https://dav.example.com/calendars/1'))->toBe('https://dav.example.com');
        expect(UrlHelper::getBaseUrl('http://localhost:8080/x'))->toBe('http://localhost');
    });

    it('returns null for an invalid base url', function () {
        expect(UrlHelper::getBaseUrl('not a url'))->toBeNull();
    });
});

describe('DavHelper', function () {
    it('builds the principal uri from the user email', function () {
        $user = new User(['email' => 'john@example.com']);

        expect(DavHelper::getPrincipalUri($user))->toBe('principals/john@example.com');
    });
});
