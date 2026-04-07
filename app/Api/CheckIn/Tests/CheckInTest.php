<?php

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('CheckIn Factory', function () {
    it('creates a valid check-in', function () {
        $checkIn = CheckIn::factory()->create();

        expect($checkIn->date)->toBeInstanceOf(\Carbon\Carbon::class);
        expect($checkIn->mood)->toBeInt();
        expect($checkIn->mood)->toBeGreaterThanOrEqual(1);
        expect($checkIn->mood)->toBeLessThanOrEqual(5);
        expect($checkIn->water)->toBeInt();
        expect($checkIn->sports)->toBeInt();
        expect($checkIn->sleep)->toBeInt();
        expect($checkIn->work)->toBeInt();
        expect($checkIn->food_quality)->toBeInt();
        expect($checkIn->food_amount)->toBeInt();
        expect($checkIn->user_id)->not()->toBeNull();
    });

    it('creates a great day check-in', function () {
        $checkIn = CheckIn::factory()->greatDay()->create();

        expect($checkIn->mood)->toBeGreaterThanOrEqual(4);
    });

    it('creates a poor day check-in', function () {
        $checkIn = CheckIn::factory()->poorDay()->create();

        expect($checkIn->mood)->toBeLessThanOrEqual(2);
    });

    it('creates a check-in for specific date', function () {
        $date = now()->subDays(5);
        $checkIn = CheckIn::factory()->forDate($date)->create();

        expect($checkIn->date)->toEqual($date);
    });

    it('creates a todays check-in', function () {
        $checkIn = CheckIn::factory()->today()->create();

        expect($checkIn->date)->toEqual(today());
    });

    it('creates a check-in for user', function () {
        $user = User::factory()->create();
        $checkIn = CheckIn::factory()->forUser($user)->create();

        expect($checkIn->user_id)->toBe($user->id);
    });
});

describe('CheckIn Model', function () {
    it('has correct fillable attributes', function () {
        $checkIn = new CheckIn;

        expect($checkIn->getFillable())->toContain('date');
        expect($checkIn->getFillable())->toContain('mood');
        expect($checkIn->getFillable())->toContain('water');
        expect($checkIn->getFillable())->toContain('sports');
        expect($checkIn->getFillable())->toContain('sleep');
        expect($checkIn->getFillable())->toContain('dreams');
        expect($checkIn->getFillable())->toContain('work');
        expect($checkIn->getFillable())->toContain('food_quality');
        expect($checkIn->getFillable())->toContain('food_amount');
        expect($checkIn->getFillable())->toContain('menstruation');
        expect($checkIn->getFillable())->toContain('alcohol');
        expect($checkIn->getFillable())->toContain('smoking');
        expect($checkIn->getFillable())->toContain('user_id');
    });

    it('casts date to date', function () {
        $checkIn = CheckIn::factory()->create();

        expect($checkIn->date)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('casts boolean fields correctly', function () {
        $checkIn = CheckIn::factory()->create([
            'menstruation' => true,
            'alcohol' => false,
            'smoking' => false,
        ]);

        expect($checkIn->menstruation)->toBeBoolean();
        expect($checkIn->alcohol)->toBeBoolean();
        expect($checkIn->smoking)->toBeBoolean();
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $checkIn = CheckIn::factory()->forUser($user)->create();

        expect($checkIn->user->id)->toBe($user->id);
    });

    it('scopes by user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        CheckIn::factory()->forUser($user1)->create();
        CheckIn::factory()->forUser($user2)->create();

        $user1Entries = CheckIn::where('user_id', $user1->id)->get();
        $user2Entries = CheckIn::where('user_id', $user2->id)->get();

        expect($user1Entries)->toHaveCount(1);
        expect($user2Entries)->toHaveCount(1);
    });
});
