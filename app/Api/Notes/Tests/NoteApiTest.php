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
            ->assertJsonCount(2, 'data');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/notes/categories/'.$categoryId, ['title' => 'Private'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Private');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/notes/categories/'.$categoryId)
            ->assertStatus(200);

        // Only the provisioned journal folder remains.
        expect(NoteCategory::count())->toBe(1);
    });
});

describe('Journal folder', function () {
    it('provisions a system, hidden, root-level journal folder per user', function () {
        $user = makeUser();

        $journal = NoteCategory::where('user_id', $user->id)
            ->where('title', NoteCategory::JOURNAL_TITLE)
            ->first();

        expect($journal)->not->toBeNull();
        expect($journal->is_system)->toBeTrue();
        expect($journal->is_hidden)->toBeTrue();
        expect($journal->parent_id)->toBeNull();
    });

    it('does not provision a second journal folder for the same user', function () {
        $user = makeUser();
        $before = NoteCategory::where('user_id', $user->id)->count();

        NoteCategory::provisionJournal($user);

        expect(NoteCategory::where('user_id', $user->id)->count())->toBe($before);
    });

    it('rejects renaming and deleting a system folder', function () {
        $user = makeUser();
        $journal = NoteCategory::where('user_id', $user->id)
            ->where('title', NoteCategory::JOURNAL_TITLE)
            ->first();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/notes/categories/'.$journal->id, ['title' => 'Renamed'])
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/notes/categories/'.$journal->id)
            ->assertStatus(403);

        expect($journal->fresh()->title)->toBe(NoteCategory::JOURNAL_TITLE);
        expect(NoteCategory::whereKey($journal->id)->exists())->toBeTrue();
    });

    it('never lets the API set is_system', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notes/categories', ['title' => 'Work', 'is_system' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.is_system', false);

        $categoryId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/notes/categories/'.$categoryId, ['title' => 'Work 2', 'is_system' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.is_system', false);

        expect(NoteCategory::whereKey($categoryId)->value('is_system'))->toBeFalsy();
    });
});

describe('Note category visibility', function () {
    it('toggles a folder hidden state', function () {
        $user = makeUser();
        $category = NoteCategory::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/notes/categories/'.$category->id.'/hidden', ['hidden' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.is_hidden', true);

        expect($category->fresh()->is_hidden)->toBeTrue();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/notes/categories/'.$category->id.'/hidden', ['hidden' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_hidden', false);
    });

    it('allows toggling hidden on a system folder', function () {
        $user = makeUser();
        $journal = NoteCategory::where('user_id', $user->id)
            ->where('title', NoteCategory::JOURNAL_TITLE)
            ->first();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/notes/categories/'.$journal->id.'/hidden', ['hidden' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_hidden', false);
    });

    it('validates the hidden flag', function () {
        $user = makeUser();
        $category = NoteCategory::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/notes/categories/'.$category->id.'/hidden', [])
            ->assertStatus(422);
    });

    it('forbids toggling hidden on another user folder', function () {
        $user = makeUser();
        $other = makeUser();
        $category = NoteCategory::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/notes/categories/'.$category->id.'/hidden', ['hidden' => true])
            ->assertStatus(403);
    });

    it('excludes notes in hidden folders from newest but keeps uncategorized notes', function () {
        $user = makeUser();
        $hidden = NoteCategory::factory()->forUser($user)->hidden()->create();
        $visible = NoteCategory::factory()->forUser($user)->create();

        Note::factory()->forUser($user)->forCategory($hidden)->create();
        Note::factory()->forUser($user)->forCategory($visible)->create();
        Note::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/newest')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
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
