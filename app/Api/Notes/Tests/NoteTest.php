<?php

use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Note Factory', function () {
    it('creates a valid note', function () {
        $note = Note::factory()->create();

        expect($note->title)->toBeString();
        expect($note->content)->toBeString();
        expect($note->user_id)->not()->toBeNull();
    });

    it('creates a favorite note', function () {
        $note = Note::factory()->favorite()->create();

        expect($note->is_favorite)->toBeTrue();
    });

    it('creates a non-favorite note', function () {
        $note = Note::factory()->notFavorite()->create();

        expect($note->is_favorite)->toBeFalse();
    });

    it('creates a short note', function () {
        $note = Note::factory()->short()->create();

        expect($note->content)->toBeString();
    });

    it('creates a long note', function () {
        $note = Note::factory()->long()->create();

        expect($note->content)->toBeString();
        expect(strlen($note->content))->toBeGreaterThan(200);
    });

    it('creates a note with custom title', function () {
        $note = Note::factory()->withTitle('Meeting Notes')->create();

        expect($note->title)->toBe('Meeting Notes');
    });
});

describe('NoteCategory Factory', function () {
    it('creates a valid category', function () {
        $user = User::factory()->create();
        $category = NoteCategory::factory()->create();

        expect($category->title)->toBeString();
        expect($category->user_id)->toBe($user->id);
    });

    it('creates a root category', function () {
        $category = NoteCategory::factory()->asRoot()->create();

        expect($category->parent_id)->toBeNull();
        expect($category->isRoot())->toBeTrue();
    });

    it('creates a category with sort order', function () {
        $category = NoteCategory::factory()->withSortOrder(10)->create();

        expect($category->sort_order)->toBe(10);
    });
});

describe('Note Model', function () {
    it('has correct fillable attributes', function () {
        $note = new Note;

        expect($note->getFillable())->toContain('title');
        expect($note->getFillable())->toContain('content');
        expect($note->getFillable())->toContain('user_id');
    });

    it('casts favorite as boolean', function () {
        $note = Note::factory()->create();

        expect($note->is_favorite)->toBeBoolean();
    });

    it('belongs to a user', function () {
        $user = User::factory()->create();
        $note = Note::factory()->forUser($user)->create();

        expect($note->user->id)->toBe($user->id);
    });

    it('can have a category', function () {
        $user = User::factory()->create();
        $category = NoteCategory::factory()->forUser($user)->create();
        $note = Note::factory()->forUser($user)->forCategory($category)->create();

        expect($note->category->id)->toBe($category->id);
    });

    it('can have tags', function () {
        $user = User::factory()->create();
        $note = Note::factory()->forUser($user)->create();
        $tag = \App\Api\Tags\Models\Tag::factory()->forUser($user)->create();

        $note->tags()->attach($tag);
        $note->load('tags');

        expect($note->tags)->toHaveCount(1);
    });

    it('scopes by user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Note::factory()->forUser($user1)->create();
        Note::factory()->forUser($user2)->create();

        $notes = Note::forUser($user1)->get();

        expect($notes)->toHaveCount(1);
    });
});

describe('NoteCategory Model', function () {
    it('belongs to a user', function () {
        $user = User::factory()->create();
        $category = NoteCategory::factory()->forUser($user)->create();

        expect($category->user->id)->toBe($user->id);
    });

    it('can have children', function () {
        $user = User::factory()->create();
        $parent = NoteCategory::factory()->forUser($user)->create();
        NoteCategory::factory()->forUser($user)->withParent($parent)->create();

        expect($parent->children)->toHaveCount(1);
    });

    it('can have descendants', function () {
        $user = User::factory()->create();
        $parent = NoteCategory::factory()->forUser($user)->create();
        $child = NoteCategory::factory()->forUser($user)->withParent($parent)->create();
        NoteCategory::factory()->forUser($user)->withParent($child)->create();

        expect($parent->descendants)->toHaveCount(2);
    });

    it('returns full path', function () {
        $user = User::factory()->create();
        $parent = NoteCategory::factory()->forUser($user)->create();
        NoteCategory::factory()->forUser($user)->withParent($parent)->create([
            'title' => 'Child',
        ]);

        expect($parent->getFullPath())->toContain('Parent > Child');
    });

    it('scopes roots correctly', function () {
        $user = User::factory()->create();
        $root1 = NoteCategory::factory()->forUser($user)->create();
        NoteCategory::factory()->forUser($user)->create();
        $parent = NoteCategory::factory()->forUser($user)->create();
        NoteCategory::factory()->forUser($user)->withParent($parent)->create();

        $roots = NoteCategory::roots()->get();

        expect($roots)->toHaveCount(2);
    });
});
