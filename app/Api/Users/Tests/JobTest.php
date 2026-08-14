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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

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
    it('runs without error when no covers exist', function () {
        Storage::fake('user_data');

        app(ScaleCovers::class)->handle(
            app(\App\Shared\Services\UserCacheService::class),
            app(\App\Shared\Services\Images\ImageTransformationService::class)
        );

        expect(true)->toBeTrue();
    });
});
