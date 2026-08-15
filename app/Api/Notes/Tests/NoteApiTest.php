<?php

use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Notes\Services\NoteImportService;
use App\Api\Notes\Services\NoteService;
use App\Api\Tags\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

describe('Note CRUD', function () {
    it('lists, stores, shows, updates and deletes notes', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes')
            ->assertStatus(200)->assertJsonCount(0, 'data');

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notes', ['title' => 'Meeting', 'content' => 'Notes about the meeting'])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Meeting');

        $noteId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/'.$noteId)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $noteId);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/notes/'.$noteId, ['title' => 'Meeting notes', 'is_favorite' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Meeting notes');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/notes/'.$noteId)
            ->assertStatus(200);

        expect(Note::count())->toBe(0);
    });

    it('returns the five newest notes', function () {
        $user = makeUser();
        Note::factory()->forUser($user)->count(7)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/newest')
            ->assertStatus(200)
            ->assertJsonCount(5, 'data');
    });

    it('scopes notes to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        $note = Note::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/'.$note->id)
            ->assertStatus(403);
    });
});

describe('Note categories CRUD', function () {
    it('lists, stores, shows, updates and deletes categories', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notes/categories', ['title' => 'Work'])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Work');

        $categoryId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/categories')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/notes/categories/'.$categoryId, ['title' => 'Private'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Private');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/notes/categories/'.$categoryId)
            ->assertStatus(200);

        expect(NoteCategory::count())->toBe(0);
    });
});

describe('Note import', function () {
    it('imports a markdown file', function () {
        $user = makeUser();

        $file = UploadedFile::fake()->createWithContent('ideas.md', '# Ideas'."\n\n".'Content here');

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/notes/import', ['file' => $file])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect(Note::where('user_id', $user->id)->where('title', 'ideas')->exists())->toBeTrue();
    });

    it('imports a zip file with a markdown note', function () {
        $user = makeUser();

        $zipPath = tempnam(sys_get_temp_dir(), 'notes');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('note.md', '# Note content');
        $zip->close();

        $file = new UploadedFile($zipPath, 'notes.zip', 'application/zip', null, true);

        try {
            $this->actingAs($user, 'sanctum')
                ->post('/api/v1/notes/import', ['file' => $file])
                ->assertStatus(200)
                ->assertJsonPath('success', true);

            expect(Note::where('user_id', $user->id)->where('title', 'note')->exists())->toBeTrue();
        } finally {
            unlink($zipPath);
        }
    });

    it('rejects unsupported file types', function () {
        $user = makeUser();

        $file = UploadedFile::fake()->createWithContent('notes.txt', 'plain text');

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/notes/import', ['file' => $file])
            ->assertStatus(400);
    });
});

describe('NoteService', function () {
    it('create attaches tags', function () {
        $user = makeUser();
        $tag = Tag::factory()->forUser($user)->create();

        $note = app(NoteService::class)->create($user, [
            'title' => 'Tagged note',
            'content' => 'x',
            'tags' => [$tag->id],
        ]);

        expect($note->tags->pluck('id'))->toContain($tag->id);
    });

    it('update syncs tags', function () {
        $user = makeUser();
        $tag = Tag::factory()->forUser($user)->create();
        $note = Note::factory()->forUser($user)->create();
        $note->tags()->attach($tag);

        app(NoteService::class)->update($note, ['tags' => []]);

        expect($note->fresh()->tags)->toHaveCount(0);
    });
});

describe('NoteImportService', function () {
    it('imports markdown files through the service', function () {
        $user = makeUser();

        $file = UploadedFile::fake()->createWithContent('journal.md', 'Journal entry');

        expect(app(NoteImportService::class)->importFile($file, $user->id))->toBeTrue();
        expect(Note::where('user_id', $user->id)->where('title', 'journal')->exists())->toBeTrue();
    });
});
