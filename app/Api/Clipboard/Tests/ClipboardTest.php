<?php

use App\Api\Clipboard\Models\Clipboard;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Clipboard Factory', function () {
    it('creates a valid clipboard entry', function () {
        $clipboard = Clipboard::factory()->create();

        expect($clipboard->content)->toBeString();
        expect($clipboard->type)->toBeString();
        expect($clipboard->user_id)->not()->toBeNull();
    });

    it('creates a text clipboard entry', function () {
        $clipboard = Clipboard::factory()->create([
            'type' => 'text',
        ]);

        expect($clipboard->type)->toBe('text');
    });

    it('creates a url clipboard entry', function () {
        $clipboard = Clipboard::factory()->withUrl()->create();

        expect($clipboard->content)->toStartWith('http');
    });

    it('creates a code clipboard entry', function () {
        $clipboard = Clipboard::factory()->asCode()->create();

        expect($clipboard->type)->toBe('code');
    });

    it('creates a file clipboard entry', function () {
        $clipboard = Clipboard::factory()->asFile()->create();

        expect($clipboard->type)->toBe('file');
        expect($clipboard->content)->toMatch('/\.[a-z]+$/');
        expect($clipboard->file_path)->not()->toBeNull();
    });

    it('creates a clipboard entry with custom content', function () {
        $content = 'Custom clipboard content';
        $clipboard = Clipboard::factory()->withContent($content)->create();

        expect($clipboard->content)->toBe($content);
    });

    it('creates a clipboard entry for user', function () {
        $user = User::factory()->create();
        $clipboard = Clipboard::factory()->forUser($user)->create();

        expect($clipboard->user_id)->toBe($user->id);
    });
});

describe('Clipboard Model', function () {
    it('has correct fillable attributes', function () {
        $clipboard = new Clipboard;

        expect($clipboard->getFillable())->toContain('content');
        expect($clipboard->getFillable())->toContain('user_id');
        expect($clipboard->getFillable())->toContain('type');
        expect($clipboard->getFillable())->toContain('file_path');
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $clipboard = Clipboard::factory()->forUser($user)->create();

        expect($clipboard->user->id)->toBe($user->id);
    });

    it('scopes by user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Clipboard::factory()->forUser($user1)->create();
        Clipboard::factory()->forUser($user2)->create();

        $user1Entries = Clipboard::where('user_id', $user1->id)->get();
        $user2Entries = Clipboard::where('user_id', $user2->id)->get();

        expect($user1Entries)->toHaveCount(1);
        expect($user2Entries)->toHaveCount(1);
    });
});
