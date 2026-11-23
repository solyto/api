<?php

use App\Api\Clipboard\Jobs\DeleteOverdueClipboardEntries;
use App\Api\Clipboard\Models\Clipboard;
use App\Api\Clipboard\Services\ClipboardService;
use App\Api\DevRequests\Jobs\DeleteOldDevRequests;
use App\Api\DevRequests\Models\DevRequest;
use App\Api\Libraries\Jobs\GenerateCoverPreview;
use App\Api\Libraries\Jobs\ScaleCovers;
use App\Api\Users\Jobs\DeleteOldFriendRequests;
use App\Api\Users\Jobs\ScaleProfileImage;
use App\Api\Users\Models\FriendRequest;
use App\Shared\Services\Images\ImageTransformationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function deleteOverdueClipboardEntries(): void
{
    app()->call([app(DeleteOverdueClipboardEntries::class), 'handle']);
}

// A music folder with one cover, under the public user directory ScaleCovers walks.
function musicFolderWithCover(string $storage, string $userFolder): string
{
    $dir = $storage.'/app/public/user/'.$userFolder.'/music';
    File::ensureDirectoryExists($dir);
    File::put($dir.'/cover.jpg', 'fake-jpeg');

    return $dir;
}

describe('DeleteOldDevRequests', function () {
    it('deletes completed or cancelled requests older than 30 days', function () {
        $old = DevRequest::factory()->completed()->create(['created_at' => now()->subDays(31)]);
        $recent = DevRequest::factory()->completed()->create(['created_at' => now()->subDay()]);
        $open = DevRequest::factory()->pending()->create(['created_at' => now()->subDays(60)]);
        $waiting = DevRequest::factory()->waitingForDeployment()->create(['created_at' => now()->subDays(60)]);
        $waitingForUser = DevRequest::factory()->waitingForUser()->create(['created_at' => now()->subDays(60)]);

        app(DeleteOldDevRequests::class)->handle();

        expect(DevRequest::find($old->id))->toBeNull();
        expect(DevRequest::find($recent->id))->not->toBeNull();
        expect(DevRequest::find($open->id))->not->toBeNull();
        expect(DevRequest::find($waiting->id))->not->toBeNull();
        expect(DevRequest::find($waitingForUser->id))->not->toBeNull();
    });
});

describe('DeleteOverdueClipboardEntries', function () {
    it('deletes entries older than a day and removes image files', function () {
        Storage::fake('private_user_data');
        Storage::disk('private_user_data')->put('clipboard/image.png', 'bytes');

        $old = Clipboard::factory()->create([
            'type' => 'image',
            'file_path' => 'clipboard/image.png',
            'created_at' => now()->subDays(2),
        ]);
        $recent = Clipboard::factory()->create(['created_at' => now()]);

        deleteOverdueClipboardEntries();

        expect(Clipboard::find($old->id))->toBeNull();
        expect(Clipboard::find($recent->id))->not->toBeNull();
        Storage::disk('private_user_data')->assertMissing('clipboard/image.png');
    });

    it('removes stored files of overdue file entries', function () {
        Storage::fake('private_user_data');
        Storage::disk('private_user_data')->put('u/clipboard/doc.pdf', 'bytes');
        Storage::disk('private_user_data')->put('u/clipboard/new.pdf', 'bytes');

        $old = Clipboard::factory()->create([
            'type' => 'file',
            'file_path' => 'u/clipboard/doc.pdf',
            'created_at' => now()->subDays(2),
        ]);
        Clipboard::factory()->create(['type' => 'file', 'file_path' => 'u/clipboard/new.pdf']);

        deleteOverdueClipboardEntries();

        expect(Clipboard::find($old->id))->toBeNull();
        Storage::disk('private_user_data')->assertMissing('u/clipboard/doc.pdf');
        Storage::disk('private_user_data')->assertExists('u/clipboard/new.pdf');
    });

    it('keeps an entry that becomes overdue after the overdue entries were selected', function () {
        Storage::fake('private_user_data');
        Storage::disk('private_user_data')->put('u/clipboard/edge.pdf', 'bytes');
        $this->travelTo(now()->startOfSecond());

        $edge = Clipboard::factory()->create([
            'type' => 'file',
            'file_path' => 'u/clipboard/edge.pdf',
            'created_at' => now()->subDay()->addSecond(),
        ]);
        $old = Clipboard::factory()->create(['created_at' => now()->subDays(2)]);

        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'select')) {
                $this->travel(5)->seconds();
            }
        });

        deleteOverdueClipboardEntries();

        expect(Clipboard::find($old->id))->toBeNull();
        expect(Clipboard::find($edge->id))->not->toBeNull();
        Storage::disk('private_user_data')->assertExists('u/clipboard/edge.pdf');
    });

    it('drops expired entries from the cached clipboard list', function () {
        $user = makeUser();
        $other = makeUser();
        $expiring = Clipboard::factory()->forUser($user)->create();
        $kept = Clipboard::factory()->forUser($user)->create();
        $otherEntry = Clipboard::factory()->forUser($other)->create();
        $service = app(ClipboardService::class);
        $service->list($user);
        $service->list($other);

        Clipboard::whereKey($expiring->id)->update(['created_at' => now()->subDays(2)]);
        Clipboard::factory()->forUser($other)->create();

        deleteOverdueClipboardEntries();

        expect($service->list($user)->pluck('id')->all())->toBe([$kept->id]);
        expect($service->list($other)->pluck('id')->all())->toBe([$otherEntry->id]);
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
        $dir = musicFolderWithCover($this->testStorage, Str::uuid()->toString());

        $imageTransformation = Mockery::mock(ImageTransformationService::class);
        $imageTransformation->shouldReceive('scaleToWidth')
            ->once()
            ->with($dir.'/cover.jpg', 400, 85)
            ->andReturn(true);

        app(ScaleCovers::class)->handle($imageTransformation);

        expect(File::exists($dir.'/cover_original.jpg'))->toBeTrue();
    });

    it('skips covers that already have an original sibling', function () {
        $dir = musicFolderWithCover($this->testStorage, Str::uuid()->toString());
        File::put($dir.'/cover_original.jpg', 'fake-jpeg-original');

        $imageTransformation = Mockery::mock(ImageTransformationService::class);
        $imageTransformation->shouldReceive('scaleToWidth')->never();

        app(ScaleCovers::class)->handle($imageTransformation);

        expect(File::exists($dir.'/cover_original.jpg'))->toBeTrue();
    });

    it('ignores folders that are not user UUIDs', function () {
        $dir = musicFolderWithCover($this->testStorage, 'not-a-uuid');

        $imageTransformation = Mockery::mock(ImageTransformationService::class);
        $imageTransformation->shouldReceive('scaleToWidth')->never();

        app(ScaleCovers::class)->handle($imageTransformation);

        expect(File::exists($dir.'/cover_original.jpg'))->toBeFalse();
    });
});
