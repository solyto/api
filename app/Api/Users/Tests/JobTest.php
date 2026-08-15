<?php

use App\Api\Clipboard\Jobs\DeleteOverdueClipboardEntries;
use App\Api\Clipboard\Models\Clipboard;
use App\Api\DevRequests\Jobs\DeleteOldDevRequests;
use App\Api\DevRequests\Models\DevRequest;
use App\Api\Libraries\Jobs\GenerateCoverPreview;
use App\Api\Libraries\Jobs\ScaleCovers;
use App\Api\Users\Jobs\DeleteOldFriendRequests;
use App\Api\Users\Jobs\ScaleProfileImage;
use App\Api\Users\Models\FriendRequest;
use App\Shared\Services\Images\ImageTransformationService;
use App\Shared\Services\UserCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('DeleteOldDevRequests', function () {
    it('deletes completed or cancelled requests older than 30 days', function () {
        $old = DevRequest::factory()->completed()->create(['created_at' => now()->subDays(31)]);
        $recent = DevRequest::factory()->completed()->create(['created_at' => now()->subDay()]);
        $open = DevRequest::factory()->open()->create(['created_at' => now()->subDays(60)]);

        app(DeleteOldDevRequests::class)->handle();

        expect(DevRequest::find($old->id))->toBeNull();
        expect(DevRequest::find($recent->id))->not->toBeNull();
        expect(DevRequest::find($open->id))->not->toBeNull();
    });
});

describe('DeleteOverdueClipboardEntries', function () {
    it('deletes entries older than a day and removes image files', function () {
        Storage::fake('user_data');
        Storage::disk('user_data')->put('clipboard/image.png', 'bytes');

        $old = Clipboard::factory()->create([
            'type' => 'image',
            'file_path' => 'clipboard/image.png',
            'created_at' => now()->subDays(2),
        ]);
        $recent = Clipboard::factory()->create(['created_at' => now()]);

        app(DeleteOverdueClipboardEntries::class)->handle(app(\App\Api\Clipboard\Services\ClipboardImageService::class));

        expect(Clipboard::find($old->id))->toBeNull();
        expect(Clipboard::find($recent->id))->not->toBeNull();
        Storage::disk('user_data')->assertMissing('clipboard/image.png');
    });
});

describe('DeleteOldFriendRequests', function () {
    it('deletes resolved friend requests older than 7 days', function () {
        $old = FriendRequest::factory()->rejected()->create(['created_at' => now()->subDays(8)]);
        $recent = FriendRequest::factory()->rejected()->create(['created_at' => now()->subDay()]);
        $pending = FriendRequest::factory()->pending()->create(['created_at' => now()->subDays(30)]);

        app(DeleteOldFriendRequests::class)->handle();

        expect(FriendRequest::find($old->id))->toBeNull();
        expect(FriendRequest::find($recent->id))->not->toBeNull();
        expect(FriendRequest::find($pending->id))->not->toBeNull();
    });
});

describe('ScaleProfileImage', function () {
    it('scales the profile image via the transformation service', function () {
        Storage::fake('user_data');
        Storage::disk('user_data')->put('profile.png', 'bytes');

        $imageService = Mockery::mock(\App\Shared\Services\Images\ImageTransformationService::class);
        $imageService->shouldReceive('scaleToWidth')->once()->andReturn(true);

        app(ScaleProfileImage::class, ['path' => 'profile.png'])->handle($imageService);
    });
});

describe('GenerateCoverPreview', function () {
    it('generates a preview via the transformation service', function () {
        $imageService = Mockery::mock(\App\Shared\Services\Images\ImageTransformationService::class);
        $imageService->shouldReceive('generatePreview')->once()->andReturn('preview.jpg');

        app(GenerateCoverPreview::class, ['disk' => 'user_data', 'path' => 'covers/1.jpg'])->handle($imageService);
    });
});

describe('ScaleCovers', function () {
    beforeEach(function () {
        $this->testStorage = sys_get_temp_dir().'/scalecovers-'.Str::uuid()->toString();
        app()->useStoragePath($this->testStorage);
    });

    afterEach(function () {
        if (isset($this->testStorage) && File::isDirectory($this->testStorage)) {
            File::deleteDirectory($this->testStorage);
        }
    });

    it('backs up and scales covers that lack an original sibling', function () {
        $dir = $this->testStorage.'/app/public/user/'.Str::uuid()->toString().'/music';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/cover.jpg', 'fake-jpeg');

        $imageTransformation = Mockery::mock(ImageTransformationService::class);
        $imageTransformation->shouldReceive('scaleToWidth')
            ->once()
            ->with($dir.'/cover.jpg', 400, 85)
            ->andReturn(true);

        app(ScaleCovers::class)->handle(
            app(UserCacheService::class),
            $imageTransformation
        );

        expect(File::exists($dir.'/cover_original.jpg'))->toBeTrue();
    });

    it('skips covers that already have an original sibling', function () {
        $dir = $this->testStorage.'/app/public/user/'.Str::uuid()->toString().'/music';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/cover.jpg', 'fake-jpeg');
        File::put($dir.'/cover_original.jpg', 'fake-jpeg-original');

        $imageTransformation = Mockery::mock(ImageTransformationService::class);
        $imageTransformation->shouldReceive('scaleToWidth')->never();

        app(ScaleCovers::class)->handle(
            app(UserCacheService::class),
            $imageTransformation
        );

        expect(File::exists($dir.'/cover_original.jpg'))->toBeTrue();
    });

    it('ignores folders that are not user UUIDs', function () {
        $dir = $this->testStorage.'/app/public/user/not-a-uuid/music';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/cover.jpg', 'fake-jpeg');

        $imageTransformation = Mockery::mock(ImageTransformationService::class);
        $imageTransformation->shouldReceive('scaleToWidth')->never();

        app(ScaleCovers::class)->handle(
            app(UserCacheService::class),
            $imageTransformation
        );

        expect(File::exists($dir.'/cover_original.jpg'))->toBeFalse();
    });
});
