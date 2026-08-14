<?php

use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\WealthField;
use App\Api\Finances\Models\WealthValue;
use App\Api\Finances\Services\WealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Budget CRUD', function () {
    it('lists, stores, shows, updates and deletes budgets', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget', [
                'title' => 'Salary',
                'type' => 'income',
                'value' => 2500.50,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Salary');

        $budgetId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budgetId, ['value' => 3000])
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/budget/'.$budgetId)
            ->assertStatus(200);

        expect(Budget::count())->toBe(0);
    });

    it('scopes budgets to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        $budget = Budget::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/budget/'.$budget->id)
            ->assertStatus(403);
    });
});

describe('Wealth fields', function () {
    it('lists, stores, updates and deletes fields', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/wealth/fields', ['title' => 'Savings'])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Savings');

        $fieldId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/wealth/fields')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$fieldId, ['title' => 'Investments'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Investments');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/wealth/fields/'.$fieldId)
            ->assertStatus(200);

        expect(WealthField::count())->toBe(0);
    });

    it('stores a wealth value for a field', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', [
                'value' => 1234.56,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.value', 1234.56);

        expect(WealthValue::where('field_id', $field->id)->count())->toBe(1);
    });
});

describe('WealthService', function () {
    it('updateValue creates a value for the field', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();

        $value = app(WealthService::class)->updateValue($field, ['value' => 99.99]);

        expect($value->field_id)->toBe($field->id);
        expect($value->value)->toBe(99.99);
    });
});
