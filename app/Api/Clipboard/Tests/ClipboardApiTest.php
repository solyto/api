<?php

use App\Api\Clipboard\Models\Clipboard;
use App\Api\Clipboard\Services\ClipboardService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('Clipboard list and store', function () {
    it('lists the users clipboard entries newest first', function () {
        $user = makeUser();
        Clipboard::factory()->forUser($user)->count(2)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/clipboard')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    });

    it('stores a text clipboard entry', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/clipboard', ['content' => 'Some copied text'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect(Clipboard::where('user_id', $user->id)->where('type', 'text')->count())->toBe(1);
    });

    it('deletes a clipboard entry', function () {
        $user = makeUser();
        $clipboard = Clipboard::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/clipboard/'.$clipboard->id)
            ->assertStatus(200);

        expect(Clipboard::count())->toBe(0);
    });

    it('forbids deleting another users entry', function () {
        $user = makeUser();
        $other = makeUser();
        $clipboard = Clipboard::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/clipboard/'.$clipboard->id)
            ->assertStatus(403);
    });
});

describe('Clipboard images', function () {
    it('stores an image and serves it back', function () {
        Storage::fake('private_user_data');
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/clipboard/image', [
                'image' => UploadedFile::fake()->image('photo.png', 200, 200),
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $clipboardId = $store->json('data.id');
        $clipboard = Clipboard::find($clipboardId);

        expect($clipboard->type)->toBe('image');
        expect($clipboard->file_path)->not->toBeNull();
        Storage::disk('private_user_data')->assertExists($clipboard->file_path);
        expect($store->json('data.file_path'))->toBe($clipboard->file_path);

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/clipboard/'.$clipboardId.'/image')
            ->assertStatus(200)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    });

    it('returns 404 when the image file is missing', function () {
        Storage::fake('private_user_data');
        $user = makeUser();
        $clipboard = Clipboard::factory()->forUser($user)->create(['type' => 'image', 'file_path' => $user->id.'/clipboard/gone.png']);

        expectApiError($this->actingAs($user, 'sanctum')->getJson('/api/v1/clipboard/'.$clipboard->id.'/image'), 404);
    });

    it('keeps images off the public disk', function () {
        Storage::fake('user_data');
        Storage::fake('private_user_data');

        $this->actingAs(makeUser(), 'sanctum')
            ->post('/api/v1/clipboard/image', ['image' => UploadedFile::fake()->image('photo.png', 20, 20)])
            ->assertStatus(200);

        expect(Storage::disk('user_data')->allFiles())->toBe([]);
    });

    it('moves existing images to the private disk', function () {
        Storage::fake('user_data');
        Storage::fake('private_user_data');
        $user = makeUser();
        $path = $user->id.'/clipboard/old.png';
        Storage::disk('user_data')->put($path, 'png-bytes');
        Storage::disk('user_data')->put($user->id.'/avatar.png', 'other');
        Clipboard::factory()->forUser($user)->create(['type' => 'image', 'file_path' => $path]);
        Clipboard::factory()->forUser($user)->create(['type' => 'image', 'file_path' => $user->id.'/clipboard/gone.png']);

        (require database_path('migrations/2026_11_16_120001_move_clipboard_images_to_private_disk.php'))->up();

        Storage::disk('user_data')->assertMissing($path);
        Storage::disk('user_data')->assertExists($user->id.'/avatar.png');
        expect(Storage::disk('private_user_data')->get($path))->toBe('png-bytes');
    });

    it('returns 404 when requesting an image for a text entry', function () {
        $user = makeUser();
        $clipboard = Clipboard::factory()->forUser($user)->create(['type' => 'text']);

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/clipboard/'.$clipboard->id.'/image')
            ->assertStatus(404);
    });

    it('deletes the stored image file with the entry', function () {
        Storage::fake('private_user_data');
        $user = makeUser();

        Storage::disk('private_user_data')->put('clipboard/image.png', 'fake-bytes');

        $clipboard = Clipboard::factory()->forUser($user)->create([
            'type' => 'image',
            'file_path' => 'clipboard/image.png',
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/clipboard/'.$clipboard->id)
            ->assertStatus(200);

        Storage::disk('private_user_data')->assertMissing('clipboard/image.png');
    });
});

// UploadedFile::fake() reports a MIME type guessed from the name; a real temp file makes finfo look at the content.
function clipboardUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'clip');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function clipboardDocx(): string
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('word/document.xml', '<w:document/>');
    $zip->close();

    return file_get_contents($path);
}

describe('Clipboard files', function () {
    beforeEach(fn () => Storage::fake('private_user_data'));

    it('stores a pdf and serves it back as an attachment', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/clipboard/file', ['file' => clipboardUpload('Report "Q3".pdf', "%PDF-1.4\n%%EOF\n")])
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'file')
            ->assertJsonPath('data.content', '')
            ->assertJsonPath('data.file_name', 'Report Q3.pdf')
            ->assertJsonPath('data.file_size', 15)
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.file_path', null);

        $clipboard = Clipboard::find($store->json('data.id'));
        Storage::disk('private_user_data')->assertExists($clipboard->file_path);
        expect($clipboard->file_path)->toStartWith($user->id.'/clipboard/')->not->toContain('Report');

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/v1/clipboard/'.$clipboard->id.'/file')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain('Report Q3.pdf');
        expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
    });

    it('accepts the allowed document types', function (string $name, string $content, string $mime) {
        $this->actingAs(makeUser(), 'sanctum')
            ->post('/api/v1/clipboard/file', ['file' => clipboardUpload($name, $content)])
            ->assertStatus(200)
            ->assertJsonPath('data.mime_type', $mime);
    })->with([
        'txt' => ['notes.txt', "plain text\n", 'text/plain'],
        'md' => ['README.MD', "# Title\n\nSome *text*.\n", 'text/markdown'],
        'csv' => ['data.csv', "a,b,c\n1,2,3\n", 'text/csv'],
        'doc' => ['old.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 504), 'application/msword'],
        'docx' => ['new.docx', clipboardDocx(), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ]);

    it('rejects disallowed types and extension/content mismatches', function (string $name, string $content) {
        $response = $this->actingAs(makeUser(), 'sanctum')
            ->post('/api/v1/clipboard/file', ['file' => clipboardUpload($name, $content)], ['Accept' => 'application/json']);

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('file');
        expect(Clipboard::count())->toBe(0);
    })->with([
        'html' => ['page.html', '<!DOCTYPE html><html><script>alert(1)</script></html>'],
        'html renamed to pdf' => ['page.pdf', '<!DOCTYPE html><html><script>alert(1)</script></html>'],
        'html renamed to txt' => ['page.txt', '<!DOCTYPE html><html><script>alert(1)</script></html>'],
        'text renamed to pdf' => ['fake.pdf', 'just text'],
        'zip renamed to pdf' => ['archive.pdf', clipboardDocx()],
        'no extension' => ['README', 'just text'],
    ]);

    it('rejects files over the size limit with 413', function () {
        config(['clipboard.max_file_kb' => 1]);

        $response = $this->actingAs(makeUser(), 'sanctum')
            ->post('/api/v1/clipboard/file', ['file' => clipboardUpload('big.txt', str_repeat('a', 1025))], ['Accept' => 'application/json']);

        expectApiError($response, 413);
        expect(Clipboard::count())->toBe(0);
    });

    it('limits file uploads per user and day', function () {
        config(['clipboard.max_files_per_day' => 2]);
        $user = makeUser();

        foreach ([200, 200, 429] as $status) {
            $this->actingAs($user, 'sanctum')
                ->post('/api/v1/clipboard/file', ['file' => clipboardUpload('a.txt', 'text')], ['Accept' => 'application/json'])
                ->assertStatus($status);
        }

        $this->actingAs(makeUser(), 'sanctum')
            ->post('/api/v1/clipboard/file', ['file' => clipboardUpload('a.txt', 'text')], ['Accept' => 'application/json'])
            ->assertStatus(200);
    });

    it('does not count rejected uploads against the daily limit', function () {
        config(['clipboard.max_files_per_day' => 1]);
        $user = makeUser();

        foreach (['a.exe', 'b.exe'] as $name) {
            $this->actingAs($user, 'sanctum')
                ->post('/api/v1/clipboard/file', ['file' => clipboardUpload($name, 'MZ')], ['Accept' => 'application/json'])
                ->assertStatus(422);
        }

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/clipboard/file', ['file' => clipboardUpload('a.txt', 'text')], ['Accept' => 'application/json'])
            ->assertStatus(200);
    });

    it('only lists file entries when asked for them', function () {
        $user = makeUser();
        Clipboard::factory()->forUser($user)->create();
        Clipboard::factory()->forUser($user)->create(['type' => 'file', 'file_path' => 'x/clipboard/a.pdf']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/clipboard')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'text');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/clipboard?include_files=1')
            ->assertJsonCount(2, 'data');
    });

    it('forbids downloading another users file', function () {
        $other = makeUser();
        Storage::disk('private_user_data')->put($other->id.'/clipboard/a.pdf', '%PDF-1.4');
        $clipboard = Clipboard::factory()->forUser($other)->create([
            'type' => 'file', 'file_path' => $other->id.'/clipboard/a.pdf', 'file_name' => 'a.pdf', 'mime_type' => 'application/pdf',
        ]);

        expectApiError($this->actingAs(makeUser(), 'sanctum')->getJson('/api/v1/clipboard/'.$clipboard->id.'/file'), 403);
    });

    it('returns 404 for the file of a text entry', function () {
        $user = makeUser();
        $clipboard = Clipboard::factory()->forUser($user)->create();

        expectApiError($this->actingAs($user, 'sanctum')->getJson('/api/v1/clipboard/'.$clipboard->id.'/file'), 404);
    });

    it('deletes the stored file with the entry', function () {
        $user = makeUser();
        $id = $this->actingAs($user, 'sanctum')
            ->post('/api/v1/clipboard/file', ['file' => clipboardUpload('a.txt', 'text')])
            ->json('data.id');
        $path = Clipboard::find($id)->file_path;

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/clipboard/'.$id)->assertStatus(200);

        Storage::disk('private_user_data')->assertMissing($path);
    });
});

describe('ClipboardService', function () {
    it('storeImage creates an image entry', function () {
        Storage::fake('private_user_data');
        $user = makeUser();

        $clipboard = app(ClipboardService::class)->storeImage(
            $user,
            UploadedFile::fake()->image('photo.png', 100, 100)
        );

        expect($clipboard)->not->toBeNull();
        expect($clipboard->type)->toBe('image');
    });

    it('storeImage returns null for invalid images', function () {
        Storage::fake('private_user_data');
        $user = makeUser();

        $clipboard = app(ClipboardService::class)->storeImage(
            $user,
            UploadedFile::fake()->create('doc.pdf', 100)
        );

        expect($clipboard)->toBeNull();
    });

    it('getImagePath returns null for non-image entries', function () {
        $user = makeUser();
        $clipboard = Clipboard::factory()->forUser($user)->create(['type' => 'text']);

        expect(app(ClipboardService::class)->getImagePath($user->id, $clipboard))->toBeNull();
    });
});
