<?php

use App\Shared\Exceptions\BlockedUrlException;
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
        'unspecified' => 'http://0.0.0.0/',
        'not http' => 'file:///etc/passwd',
        'credentials in url' => 'https://user:pass@cloud.example.com/',
        'no host' => 'https:///path',
    ])->throws(BlockedUrlException::class);

    it('refuses a host that resolves to an internal address', function () {
        (new OutboundUrlGuard(fn () => ['93.184.216.34', '10.0.0.1']))->check('https://rebind.example.com/');
    })->throws(BlockedUrlException::class);

    it('refuses a host that does not resolve', function () {
        (new OutboundUrlGuard(fn () => []))->check('https://nowhere.example.com/');
    })->throws(BlockedUrlException::class);
});
