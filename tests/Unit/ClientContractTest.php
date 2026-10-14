<?php

use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Client contract', function () {
    // Since 1.0.15. solyto/app src/routes/setup/+page.svelte:37
    it('keeps app and version at the top level of health', function () {
        $response = $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('app', 'solyto');

        expect($response->json('version'))->toBeString()->not->toBeEmpty();
    });

    it('keeps the standard envelope on health', function () {
        $response = $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'OK')
            ->assertJsonPath('data.app', 'solyto');

        expect($response->json('data.version'))->toBeString()->not->toBeEmpty()
            ->and($response->json('data.version'))->toBe($response->json('version'));
    });

    // Since 1.0.15. solyto/app src/lib/state/Notes.svelte.ts:83-84
    it('leaves hidden and system note categories and their descendants out of the plain list', function () {
        $user = makeUser();
        $journal = NoteCategory::forUser($user->id)->where('title', NoteCategory::JOURNAL_TITLE)->firstOrFail();
        NoteCategory::factory()->forUser($user)->withParent($journal)->withTitle('Journal child')->create();
        $hidden = NoteCategory::factory()->forUser($user)->hidden()->withTitle('Hidden')->create();
        $hiddenChild = NoteCategory::factory()->forUser($user)->withParent($hidden)->withTitle('Hidden child')->create();
        NoteCategory::factory()->forUser($user)->withParent($hiddenChild)->withTitle('Hidden grandchild')->create();
        $visible = NoteCategory::factory()->forUser($user)->withTitle('Visible')->create();
        $visibleHiddenChild = NoteCategory::factory()->forUser($user)->withParent($visible)->hidden()->withTitle('Visible hidden child')->create();
        NoteCategory::factory()->forUser($user)->withParent($visibleHiddenChild)->withTitle('Visible hidden grandchild')->create();
        NoteCategory::factory()->forUser($user)->withParent($visible)->withTitle('Visible child')->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notes/categories')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Visible')
            ->assertJsonCount(1, 'data.0.children')
            ->assertJsonPath('data.0.children.0.title', 'Visible child')
            ->assertJsonCount(0, 'data.0.children.0.children');

        expect($response->getContent())
            ->not->toContain(NoteCategory::JOURNAL_TITLE)
            ->not->toContain('Journal child')
            ->not->toContain('Hidden');
    });

    // Since 1.0.15. solyto/app src/lib/state/Notes.svelte.ts:53-56
    it('leaves notes in hidden and system categories and their descendants out of the plain list', function () {
        $user = makeUser();
        $journal = NoteCategory::forUser($user->id)->where('title', NoteCategory::JOURNAL_TITLE)->firstOrFail();
        $hidden = NoteCategory::factory()->forUser($user)->hidden()->create();
        $hiddenChild = NoteCategory::factory()->forUser($user)->withParent($hidden)->create();
        $visible = NoteCategory::factory()->forUser($user)->create();

        Note::factory()->forUser($user)->create(['title' => '2026-10-14', 'category_id' => $journal->id]);
        Note::factory()->forUser($user)->create(['title' => 'In hidden', 'category_id' => $hidden->id]);
        Note::factory()->forUser($user)->create(['title' => 'In hidden child', 'category_id' => $hiddenChild->id]);
        Note::factory()->forUser($user)->create(['title' => 'In visible', 'category_id' => $visible->id]);
        Note::factory()->forUser($user)->create(['title' => 'Uncategorized', 'category_id' => null]);

        $titles = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/notes')
                ->assertStatus(200)
                ->json('data')
        )->pluck('title')->all();

        expect($titles)->toEqualCanonicalizing(['In visible', 'Uncategorized']);
    });
});
