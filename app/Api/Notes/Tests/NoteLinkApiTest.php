<?php

use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Users\Models\User;
use Illuminate\Http\UploadedFile;

function noteLink(Note $note): string
{
    return '<a href="/notes/'.$note->id.'">'.$note->title.'</a>';
}

function linkedIds(Note $note): array
{
    return $note->linkedNotes()->pluck('notes.id')->sort()->values()->all();
}

function backlinkTitles($test, User $user, Note $note): array
{
    return collect($test->actingAs($user, 'sanctum')
        ->getJson('/api/v1/notes/'.$note->id.'/backlinks')
        ->assertOk()
        ->json('data'))->pluck('title')->all();
}

describe('Note links on save', function () {
    it('stores the links of a new note and lists it as a backlink', function () {
        $user = makeUser();
        $target = Note::factory()->forUser($user)->create(['title' => 'Target']);

        $id = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notes', ['title' => 'Source', 'content' => '<p>See '.noteLink($target).'</p>'])
            ->assertStatus(201)
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/'.$target->id.'/backlinks')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [['id' => $id, 'title' => 'Source']]);
    });

    it('replaces the links when the content changes', function () {
        $user = makeUser();
        $first = Note::factory()->forUser($user)->create();
        $second = Note::factory()->forUser($user)->create();
        $source = Note::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/notes/'.$source->id, ['content' => noteLink($first)])
            ->assertOk();
        expect(linkedIds($source))->toBe([$first->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/notes/'.$source->id, ['content' => noteLink($second)])
            ->assertOk();
        expect(linkedIds($source))->toBe([$second->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/notes/'.$source->id, ['content' => '<p>No links</p>'])
            ->assertOk();
        expect(linkedIds($source))->toBe([]);
    });

    it('keeps the links when an update does not write the content', function () {
        $user = makeUser();
        $target = Note::factory()->forUser($user)->create();
        $source = Note::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/notes/'.$source->id, ['content' => noteLink($target)])->assertOk();
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/notes/'.$source->id, ['title' => 'Renamed'])->assertOk();

        expect(linkedIds($source))->toBe([$target->id]);
    });

    it('finds HTML, Markdown and absolute links, in any case, once per target', function () {
        $user = makeUser();
        $html = Note::factory()->forUser($user)->create();
        $markdown = Note::factory()->forUser($user)->create();
        $absolute = Note::factory()->forUser($user)->create();

        $content = noteLink($html)
            ."\n[md](/notes/{$markdown->id})"
            ."\nhttps://solyto.example.org/notes/".strtoupper($absolute->id)
            ."\n".noteLink($html);

        $source = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notes', ['title' => 'Source', 'content' => $content])
            ->assertStatus(201)
            ->json('data.id');

        expect(linkedIds(Note::find($source)))->toBe(collect([$html->id, $markdown->id, $absolute->id])->sort()->values()->all());
    });

    it('ignores self-links, unknown ids and other users\' notes', function () {
        $user = makeUser();
        $foreign = Note::factory()->forUser(makeUser())->create();
        $source = Note::factory()->forUser($user)->create();

        $content = noteLink($source).noteLink($foreign).'<a href="/notes/00000000-0000-0000-0000-000000000000">x</a>';
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/notes/'.$source->id, ['content' => $content])->assertOk();

        expect(linkedIds($source))->toBe([]);
    });

    it('counts only links whose uuid is a whole /notes path segment', function () {
        $user = makeUser();
        $valid = Note::factory()->forUser($user)->create();
        $query = Note::factory()->forUser($user)->create();
        $proto = Note::factory()->forUser($user)->create();
        $dash = Note::factory()->forUser($user)->create();
        $suffix = Note::factory()->forUser($user)->create();
        $extra = Note::factory()->forUser($user)->create();
        $api = Note::factory()->forUser($user)->create();

        $content = noteLink($valid)
            ."\nSee /notes/{$query->id}?x=1"
            ."\n//host/notes/{$proto->id}"
            ."\n[bad](/notes/{$dash->id}-draft)"
            ."\n[bad](/notes/{$suffix->id}suffix)"
            ."\n[bad](/notes/{$extra->id}/extra)"
            ."\n/api/v1/notes/{$api->id}";

        $source = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notes', ['title' => 'Source', 'content' => $content])
            ->assertStatus(201)
            ->json('data.id');

        expect(linkedIds(Note::find($source)))->toBe(collect([$valid->id, $query->id])->sort()->values()->all());
    });

    it('counts links with a trailing slash, a query or a fragment', function () {
        $user = makeUser();
        $slash = Note::factory()->forUser($user)->create();
        $slashFragment = Note::factory()->forUser($user)->create();
        $slashQuery = Note::factory()->forUser($user)->create();
        $fragment = Note::factory()->forUser($user)->create();
        $markdown = Note::factory()->forUser($user)->create();
        $doubleSlash = Note::factory()->forUser($user)->create();

        $content = '<a href="/notes/'.$slash->id.'/">a</a>'
            .'<a href="/notes/'.$slashFragment->id.'/#heading">b</a>'
            .'<a href="https://solyto.example.org/notes/'.$slashQuery->id.'/?x=1">c</a>'
            .'<a href="/notes/'.$fragment->id.'#heading">d</a>'
            ."\n[md](/notes/{$markdown->id}/)"
            .'<a href="/notes/'.$doubleSlash->id.'//">e</a>';

        $source = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notes', ['title' => 'Source', 'content' => $content])
            ->assertStatus(201)
            ->json('data.id');

        expect(linkedIds(Note::find($source)))
            ->toBe(collect([$slash->id, $slashFragment->id, $slashQuery->id, $fragment->id, $markdown->id])->sort()->values()->all());
    });

    it('drops the link row but keeps the source text when the target is deleted', function () {
        $user = makeUser();
        $target = Note::factory()->forUser($user)->create();
        $content = '<p>'.noteLink($target).'</p>';
        $source = Note::factory()->forUser($user)->create();
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/notes/'.$source->id, ['content' => $content])->assertOk();

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/notes/'.$target->id)->assertOk();

        expect(linkedIds($source))->toBe([])
            ->and($source->fresh()->content)->toBe($content);
    });

    it('drops the backlink when the source is deleted', function () {
        $user = makeUser();
        $target = Note::factory()->forUser($user)->create();
        $source = Note::factory()->forUser($user)->create();
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/notes/'.$source->id, ['content' => noteLink($target)])->assertOk();

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/notes/'.$source->id)->assertOk();

        expect(backlinkTitles($this, $user, $target))->toBe([]);
    });
});

describe('Note backlinks endpoint', function () {
    it('sorts backlinks by title', function () {
        $user = makeUser();
        $target = Note::factory()->forUser($user)->create();
        foreach (['Banana', 'Apple', 'Cherry'] as $title) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/notes', ['title' => $title, 'content' => noteLink($target)])
                ->assertStatus(201);
        }

        expect(backlinkTitles($this, $user, $target))->toBe(['Apple', 'Banana', 'Cherry']);
    });

    it('leaves out sources in hidden categories and their subfolders', function () {
        $user = makeUser();
        $hidden = NoteCategory::factory()->forUser($user)->hidden()->create();
        $child = NoteCategory::factory()->forUser($user)->create(['parent_id' => $hidden->id]);
        $visible = NoteCategory::factory()->forUser($user)->create();
        $target = Note::factory()->forUser($user)->create();

        foreach (['Hidden' => $hidden->id, 'Child' => $child->id, 'Visible' => $visible->id, 'Root' => null] as $title => $categoryId) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/notes', ['title' => $title, 'content' => noteLink($target), 'category_id' => $categoryId])
                ->assertStatus(201);
        }

        expect(backlinkTitles($this, $user, $target))->toBe(['Root', 'Visible']);
    });

    it('forbids another user\'s note and 404s an unknown one', function () {
        $user = makeUser();
        $foreign = Note::factory()->forUser(makeUser())->create();

        expectApiError($this->actingAs($user, 'sanctum')->getJson('/api/v1/notes/'.$foreign->id.'/backlinks'), 403);
        expectApiError($this->actingAs($user, 'sanctum')->getJson('/api/v1/notes/00000000-0000-0000-0000-000000000000/backlinks'), 404);
    });

    it('requires authentication', function () {
        $note = Note::factory()->create();

        $this->getJson('/api/v1/notes/'.$note->id.'/backlinks')->assertStatus(401);
    });
});

describe('Note links from import and backfill', function () {
    it('stores links from an imported markdown file', function () {
        $user = makeUser();
        $target = Note::factory()->forUser($user)->create();

        $file = UploadedFile::fake()->createWithContent('ideas.md', "See [target](/notes/{$target->id})");
        $this->actingAs($user, 'sanctum')->post('/api/v1/notes/import', ['file' => $file])->assertOk();

        expect(linkedIds(Note::forUser($user->id)->where('title', 'ideas')->firstOrFail()))->toBe([$target->id]);
    });

    it('stores links from notes in an imported zip file', function () {
        $user = makeUser();
        $target = Note::factory()->forUser($user)->create();

        $zipPath = tempnam(sys_get_temp_dir(), 'notes');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('folder/linked.md', "See [target](/notes/{$target->id})");
        $zip->close();

        try {
            $file = new UploadedFile($zipPath, 'notes.zip', 'application/zip', null, true);
            $this->actingAs($user, 'sanctum')->post('/api/v1/notes/import', ['file' => $file])->assertOk();

            expect(linkedIds(Note::forUser($user->id)->where('title', 'linked')->firstOrFail()))->toBe([$target->id]);
        } finally {
            unlink($zipPath);
        }
    });

    it('backfills links that existing notes already contain', function () {
        $user = makeUser();
        $target = Note::factory()->forUser($user)->create();
        $source = Note::factory()->forUser($user)->create(['content' => noteLink($target)]);
        $foreignSource = Note::factory()->forUser(makeUser())->create(['content' => noteLink($target)]);

        expect(linkedIds($source))->toBe([]);

        (require base_path('database/migrations/2026_11_24_000002_backfill_note_links.php'))->up();

        expect(linkedIds($source))->toBe([$target->id])
            ->and(linkedIds($foreignSource))->toBe([]);
    });
});
