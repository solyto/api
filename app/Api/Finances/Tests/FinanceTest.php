<?php

use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\WealthField;
use App\Api\Finances\Models\WealthValue;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Budget Factory', function () {
    it('creates a valid budget', function () {
        $budget = Budget::factory()->create();

        expect($budget->title)->toBeString();
        expect($budget->type)->toBeString();
        expect($budget->value)->toBeFloat();
        expect($budget->user_id)->not()->toBeNull();
    });

    it('creates an income budget', function () {
        $budget = Budget::factory()->income()->create();

        expect($budget->type)->toBe('income');
        expect($budget->value)->toBeGreaterThan(0);
    });

    it('creates an expense budget', function () {
        $budget = Budget::factory()->expense()->create();

        expect($budget->type)->toBe('expense');
        expect($budget->value)->toBeGreaterThan(0);
    });

    it('creates a budget with custom title', function () {
        $budget = Budget::factory()->withTitle('Monthly Groceries')->create();

        expect($budget->title)->toBe('Monthly Groceries');
    });

    it('creates a budget with custom value', function () {
        $budget = Budget::factory()->withValue(500)->create();

        expect($budget->value)->toBe(500.0);
    });

    it('creates a budget for user', function () {
        $user = User::factory()->create();
        $budget = Budget::factory()->forUser($user)->create();

        expect($budget->user_id)->toBe($user->id);
    });
});

describe('WealthField Factory', function () {
    it('creates a valid wealth field', function () {
        $field = WealthField::factory()->create();

        expect($field->title)->toBeString();
        expect($field->user_id)->not()->toBeNull();
    });

    it('creates a field with custom title', function () {
        $field = WealthField::factory()->withTitle('Savings Account')->create();

        expect($field->title)->toBe('Savings Account');
    });

    it('creates a field for user', function () {
        $user = User::factory()->create();
        $field = WealthField::factory()->forUser($user)->create();

        expect($field->user_id)->toBe($user->id);
    });
});

describe('WealthValue Factory', function () {
    it('creates a valid wealth value', function () {
        $field = WealthField::factory()->create();
        $value = WealthValue::factory()->forField($field)->create();

        expect($value->date)->toBeInstanceOf(\Carbon\Carbon::class);
        expect($value->value)->toBeFloat();
        expect($value->field_id)->toBe($field->id);
    });

    it('creates a value with custom date', function () {
        $date = now()->subMonths(6);
        $field = WealthField::factory()->create();
        $value = WealthValue::factory()->forField($field)->create([
            'date' => $date,
        ]);

        expect($value->date)->toEqual($date);
    });

    it('creates a value with custom value', function () {
        $customValue = 10000.50;
        $field = WealthField::factory()->create();
        $value = WealthValue::factory()->forField($field)->create([
            'value' => $customValue,
        ]);

        expect($value->value)->toBe($customValue);
    });
});

describe('Budget Model', function () {
    it('has correct fillable attributes', function () {
        $budget = new Budget;

        expect($budget->getFillable())->toContain('title');
        expect($budget->getFillable())->toContain('type');
        expect($budget->getFillable())->toContain('value');
        expect($budget->getFillable())->toContain('user_id');
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $budget = Budget::factory()->forUser($user)->create();

        expect($budget->user->id)->toBe($user->id);
    });

    it('scopes by user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Budget::factory()->forUser($user1)->create();
        Budget::factory()->forUser($user2)->create();

        $user1Budgets = Budget::where('user_id', $user1->id)->get();
        $user2Budgets = Budget::where('user_id', $user2->id)->get();

        expect($user1Budgets)->toHaveCount(1);
        expect($user2Budgets)->toHaveCount(1);
    });
});

describe('WealthField Model', function () {
    it('has correct fillable attributes', function () {
        $field = new WealthField;

        expect($field->getFillable())->toContain('title');
        expect($field->getFillable())->toContain('user_id');
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $field = WealthField::factory()->forUser($user)->create();

        expect($field->user->id)->toBe($user->id);
    });

    it('has values relationship', function () {
        $field = WealthField::factory()->create();
        WealthValue::factory()->forField($field)->create(3);

        expect($field->values)->toHaveCount(3);
    });

    it('scopes by user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        WealthField::factory()->forUser($user1)->create();
        WealthField::factory()->forUser($user2)->create();

        $user1Fields = WealthField::where('user_id', $user1->id)->get();
        $user2Fields = WealthField::where('user_id', $user2->id)->get();

        expect($user1Fields)->toHaveCount(1);
        expect($user2Fields)->toHaveCount(1);
    });
});

describe('WealthValue Model', function () {
    it('has correct fillable attributes', function () {
        $value = new WealthValue;

        expect($value->getFillable())->toContain('date');
        expect($value->getFillable())->toContain('value');
        expect($value->getFillable())->toContain('field_id');
    });

    it('casts date correctly', function () {
        $date = now();
        $field = WealthField::factory()->create();
        $value = WealthValue::factory()->forField($field)->create([
            'date' => $date,
        ]);

        expect($value->date)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('casts value as float', function () {
        $value = 1234.56;
        $field = WealthField::factory()->create();
        WealthValue::factory()->forField($field)->create([
            'value' => $value,
        ]);

        expect($value->value)->toBeFloat();
    });

    it('belongs to field', function () {
        $field = WealthField::factory()->create();
        $value = WealthValue::factory()->forField($field)->create();

        expect($value->field->id)->toBe($field->id);
    });
});
