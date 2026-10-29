<?php

use App\Shared\Services\Images\ImgproxyDriver;
use Illuminate\Support\Facades\Http;

covers(ImgproxyDriver::class);

beforeEach(function () {
    config([
        'services.imgproxy.url' => 'http://imgproxy.test',
        'services.imgproxy.key' => 'aa',
        'services.imgproxy.salt' => 'bb',
    ]);

    $this->path = tempnam(sys_get_temp_dir(), 'img_');
    file_put_contents($this->path, str_repeat('x', 300 * 1024));
});

afterEach(function () {
    @unlink($this->path);
});

describe('ImgproxyDriver::scaleToFileSize', function () {
    it('returns true once a scaled version fits', function () {
        Http::fake(['http://imgproxy.test/*' => Http::response(str_repeat('x', 1024))]);

        expect((new ImgproxyDriver())->scaleToFileSize($this->path, 200 * 1024))->toBeTrue();
        expect(filesize($this->path))->toBe(1024);
        Http::assertSentCount(1);
    });

    it('gives up after the 100px attempt when the image is still too large', function () {
        $requests = 0;
        Http::fake(function () use (&$requests) {
            if (++$requests > 20) {
                throw new RuntimeException('scaleToFileSize kept requesting the same width');
            }

            return Http::response(str_repeat('x', 300 * 1024));
        });

        expect((new ImgproxyDriver())->scaleToFileSize($this->path, 200 * 1024))->toBeFalse();
        expect(Http::recorded()->last()[0]->url())->toContain('/rs:fit:100:0/');
    });
});
