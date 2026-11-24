<?php

use App\Shared\Exceptions\BlockedUrlException;
use App\Shared\Exceptions\UnresolvableHostException;
use App\Shared\Services\OutboundUrlGuard;

covers(OutboundUrlGuard::class);

describe('OutboundUrlGuard', function () {
    it('pins a public host to the address it resolved to', function () {
        $guard = new OutboundUrlGuard(fn (string $host) => $host === 'cloud.example.com' ? ['93.184.216.34'] : []);

        expect($guard->check('https://cloud.example.com/remote.php/dav/'))->toBe('cloud.example.com:443:93.184.216.34')
            ->and($guard->check('http://cloud.example.com:8080/'))->toBe('cloud.example.com:8080:93.184.216.34');
    });

    it('refuses private, loopback, link-local and reserved targets', function (string $url) {
        (new OutboundUrlGuard(fn () => ['93.184.216.34']))->check($url);
    })->with([
        'loopback' => 'http://127.0.0.1/',
        'private 10' => 'https://10.1.2.3/',
        'private 172' => 'https://172.16.0.1/',
        'private 192' => 'https://192.168.0.1/',
        'link-local' => 'http://169.254.169.254/latest/meta-data',
        'carrier-grade nat' => 'http://100.64.0.1/',
        'ipv6 loopback' => 'http://[::1]/',
        'ipv6 unique local' => 'http://[fd00::1]/',
        'ipv4-mapped loopback' => 'http://[::ffff:127.0.0.1]/',
        'local-use nat64' => 'http://[64:ff9b:1::a00:1]/',
        'nat64 private' => 'http://[64:ff9b::a00:1]/',
        'nat64 loopback' => 'http://[64:ff9b::7f00:1]/',
        'nat64 link-local' => 'http://[64:ff9b::a9fe:a9fe]/',
        'siit ipv4-translated' => 'http://[::ffff:0:a00:1]/',
        '6to4' => 'http://[2002:a00:1::1]/',
        'site-local' => 'http://[fec0::1]/',
        'unspecified' => 'http://0.0.0.0/',
        'not http' => 'file:///etc/passwd',
        'credentials in url' => 'https://user:pass@cloud.example.com/',
        'no host' => 'https:///path',
    ])->throws(BlockedUrlException::class);

    it('refuses a host that resolves to an internal address', function () {
        (new OutboundUrlGuard(fn () => ['93.184.216.34', '10.0.0.1']))->check('https://rebind.example.com/');
    })->throws(BlockedUrlException::class);

    it('refuses a host that does not resolve, as unresolvable rather than not allowed', function () {
        (new OutboundUrlGuard(fn () => []))->check('https://nowhere.example.com/');
    })->throws(UnresolvableHostException::class);

    it('allows a NAT64 address that embeds a public IPv4 address', function () {
        $guard = new OutboundUrlGuard(fn () => ['64:ff9b::5db8:d822']);

        expect($guard->check('https://ipv4-only.example.com/feed'))->toBe('ipv4-only.example.com:443:[64:ff9b::5db8:d822]');
    });

    it('judges an address that embeds an IPv4 address by that IPv4 address', function (string $address, bool $isPublic) {
        expect((new OutboundUrlGuard(fn () => []))->isPublic($address))->toBe($isPublic);
    })->with([
        'ipv4-mapped public, refused as reserved before the check' => ['::ffff:93.184.216.34', false],
        'ipv4-mapped private' => ['::ffff:10.0.0.1', false],
        'ipv4-compatible public' => ['::5db8:d822', true],
        'ipv4-compatible private' => ['::a00:1', false],
        'ipv4-compatible loopback' => ['::7f00:1', false],
        'nat64 public' => ['64:ff9b::5db8:d822', true],
        'nat64 private' => ['64:ff9b::a00:1', false],
        'plain ipv6' => ['2606:4700::1111', true],
    ]);

    it('refuses a host whose NAT64 address embeds an internal IPv4 address', function () {
        (new OutboundUrlGuard(fn () => ['64:ff9b::5db8:d822', '64:ff9b::c0a8:1']))->check('https://rebind.example.com/');
    })->throws(BlockedUrlException::class);
});
