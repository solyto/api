<?php

use App\Shared\Helpers\UrlHelper;

describe('UrlHelper::extractUrl', function () {
    it('extracts an http url from a text', function () {
        expect(UrlHelper::extractUrl('Check this out: https://example.com/page?q=1 now!'))
            ->toBe('https://example.com/page?q=1');
    });

    it('extracts an https url', function () {
        expect(UrlHelper::extractUrl('https://example.com'))->toBe('https://example.com');
    });

    it('returns null when no url is present', function () {
        expect(UrlHelper::extractUrl('There is no link here'))->toBeNull();
    });

    it('stops at whitespace', function () {
        expect(UrlHelper::extractUrl('Go to https://example.com and read'))->toBe('https://example.com');
    });

    it('extracts the first url when multiple are present', function () {
        expect(UrlHelper::extractUrl('https://first.com then https://second.com'))
            ->toBe('https://first.com');
    });
});

describe('UrlHelper::hasUrl', function () {
    it('detects https urls', function () {
        expect(UrlHelper::hasUrl('See https://example.com'))->toBeTrue();
    });

    it('detects http urls', function () {
        expect(UrlHelper::hasUrl('See http://example.com'))->toBeTrue();
    });

    it('detects www urls', function () {
        expect(UrlHelper::hasUrl('See www.example.com'))->toBeTrue();
    });

    it('returns false for plain text', function () {
        expect(UrlHelper::hasUrl('No urls here'))->toBeFalse();
    });

    it('returns false for an empty string', function () {
        expect(UrlHelper::hasUrl(''))->toBeFalse();
    });
});
