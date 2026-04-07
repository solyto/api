<?php

use App\Api\Tags\Models\Tag;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Tag Factory', function () {
    it('creates a valid tag', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->forUser($user)->create();

        expect($tag->name)->toBeString();
        expect($tag->user_id)->toBe($user->id);
        expect($tag->color)->toBeString();
    });

    it('creates a tag with custom name', function () {
        $tag = Tag::factory()->withName('Important')->create();

        expect($tag->name)->toBe('Important');
    });

    it('creates a tag with custom color', function () {
        $tag = Tag::factory()->withColor('#FF5733')->create();

        expect($tag->color)->toBe('#FF5733');
    });
});

describe('Tag Model', function () {
    it('has correct fillable attributes', function () {
        $tag = new Tag;

        expect($tag->getFillable())->toContain('name');
        expect($tag->getFillable())->toContain('user_id');
        expect($tag->getFillable())->toContain('color');
    });

    it('belongs to a user', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->forUser($user)->create();

        expect($tag->user->id)->toBe($user->id);
    });

    it('scopes by user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Tag::factory()->forUser($user1)->create();
        Tag::factory()->forUser($user2)->create();

        $tags = Tag::forUser($user1)->get();

        expect($tags)->toHaveCount(1);
    });
});
