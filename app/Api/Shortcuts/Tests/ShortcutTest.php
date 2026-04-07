<?php

use App\Api\Shortcuts\Models\Shortcut;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Shortcut Factory', function () {
    it('creates a valid shortcut', function () {
        $shortcut = Shortcut::factory()->create();

        expect($shortcut->title)->toBeString();
        expect($shortcut->url)->toBeString();
        expect($shortcut->order)->toBeInt();
        expect($shortcut->user_id)->not()->toBeNull();
    });

    it('creates a shortcut with custom title', function () {
        $shortcut = Shortcut::factory()->withTitle('GitHub')->create();

        expect($shortcut->title)->toBe('GitHub');
    });

    it('creates a shortcut with custom url', function () {
        $url = 'https://github.com';
        $shortcut = Shortcut::factory()->withUrl($url)->create();

        expect($shortcut->url)->toBe($url);
    });

    it('creates a shortcut with custom order', function () {
        $shortcut = Shortcut::factory()->withOrder(5)->create();

        expect($shortcut->order)->toBe(5);
    });

    it('creates a search engine shortcut', function () {
        $shortcut = Shortcut::factory()->searchEngine()->create();

        expect($shortcut->title)->toContain('Google', 'Bing', 'DuckDuckGo');
        expect($shortcut->url)->toContain('google.com', 'bing.com', 'duckduckgo.com');
    });

    it('creates a social media shortcut', function () {
        $shortcut = Shortcut::factory()->socialMedia()->create();

        expect($shortcut->title)->toContain('Twitter', 'LinkedIn', 'Instagram');
    });

    it('creates a shortcut for user', function () {
        $user = User::factory()->create();
        $shortcut = Shortcut::factory()->forUser($user)->create();

        expect($shortcut->user_id)->toBe($user->id);
    });
});

describe('Shortcut Model', function () {
    it('has correct fillable attributes', function () {
        $shortcut = new Shortcut;

        expect($shortcut->getFillable())->toContain('title');
        expect($shortcut->getFillable())->toContain('url');
        expect($shortcut->getFillable())->toContain('order');
        expect($shortcut->getFillable())->toContain('user_id');
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $shortcut = Shortcut::factory()->forUser($user)->create();

        expect($shortcut->user->id)->toBe($user->id);
    });

    it('scopes by user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Shortcut::factory()->forUser($user1)->create();
        Shortcut::factory()->forUser($user2)->create();

        $user1Entries = Shortcut::where('user_id', $user1->id)->get();
        $user2Entries = Shortcut::where('user_id', $user2->id)->get();

        expect($user1Entries)->toHaveCount(1);
        expect($user2Entries)->toHaveCount(1);
    });
});
