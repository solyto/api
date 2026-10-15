<?php

use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\BudgetValue;
use App\Api\Finances\Models\WealthField;
use App\Api\Finances\Models\WealthValue;
use App\Api\Finances\Services\WealthService;

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

describe('Wealth values', function () {
    it('adds a value dated today when no date is given', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', ['value' => 10])
            ->assertStatus(200);

        expect(WealthValue::where('field_id', $field->id)->first()->date->toDateString())->toBe(today()->toDateString());
    });

    it('adds a value for a past date', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();
        $date = today()->subDays(10)->toDateString();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', ['value' => 10, 'date' => $date])
            ->assertStatus(200);

        expect(WealthValue::where('field_id', $field->id)->first()->date->toDateString())->toBe($date);
    });

    it('replaces the value of the same day instead of adding a second one', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();
        $date = today()->subDays(3)->toDateString();

        foreach ([100, 200] as $amount) {
            $this->actingAs($user, 'sanctum')
                ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', ['value' => $amount, 'date' => $date])
                ->assertStatus(200);
        }

        $values = WealthValue::where('field_id', $field->id)->get();
        expect($values)->toHaveCount(1);
        expect($values->first()->value)->toBe(200.0);
    });

    it('rejects a date in the future', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', [
                'value' => 10,
                'date' => today()->addDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    });

    it('updates the amount and the date of a value', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();
        $value = WealthValue::factory()->forField($field)->historical(5)->withValue(10)->create();
        $date = today()->subDays(7)->toDateString();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$value->id, ['value' => 20, 'date' => $date])
            ->assertStatus(200)
            ->assertJsonPath('data.value', 20);

        $value->refresh();
        expect($value->value)->toBe(20.0);
        expect($value->date->toDateString())->toBe($date);
    });

    it('rejects moving a value to a date that already has a value', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();
        $value = WealthValue::factory()->forField($field)->historical(5)->create();
        WealthValue::factory()->forField($field)->historical(7)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$value->id, [
                'date' => today()->subDays(7)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        expect($value->fresh()->date->toDateString())->toBe(today()->subDays(5)->toDateString());
    });

    it('deletes a value', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();
        $value = WealthValue::factory()->forField($field)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$value->id)
            ->assertStatus(200);

        expect(WealthValue::count())->toBe(0);
    });

    it('forbids adding, changing and deleting values of another user', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser(makeUser())->create();
        $value = WealthValue::factory()->forField($field)->withValue(10)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', ['value' => 1])
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$value->id, ['value' => 1])
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$value->id)
            ->assertStatus(403);

        expect(WealthValue::count())->toBe(1);
        expect($value->fresh()->value)->toBe(10.0);
    });

    it('rejects a value that belongs to another field', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();
        $foreignValue = WealthValue::factory()->forField(WealthField::factory()->forUser(makeUser())->create())->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$foreignValue->id, ['value' => 1])
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$foreignValue->id)
            ->assertStatus(403);

        expect(WealthValue::count())->toBe(1);
    });

    it('returns 404 for a value that does not exist', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/999999')
            ->assertStatus(404);
    });

    it('uses the latest date as current value after a backfill and orders values by date', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', ['value' => 300])
            ->assertStatus(200);
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', [
                'value' => 100,
                'date' => today()->subDays(30)->toDateString(),
            ])
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/wealth/fields')
            ->assertStatus(200)
            ->assertJsonPath('data.0.currentValue.value', 300)
            ->assertJsonPath('data.0.values.0.value', 100)
            ->assertJsonPath('data.0.values.1.value', 300);
    });

    it('invalidates the cached field list on every value change', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();
        $value = WealthValue::factory()->forField($field)->today()->withValue(10)->create();

        $list = fn () => $this->actingAs($user, 'sanctum')->getJson('/api/v1/finances/wealth/fields');

        $list()->assertJsonPath('data.0.currentValue.value', 10);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', ['value' => 20]);
        $list()->assertJsonPath('data.0.currentValue.value', 20);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$value->id, ['value' => 30]);
        $list()->assertJsonPath('data.0.currentValue.value', 30);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$value->id);
        $list()->assertJsonPath('data.0.currentValue', null);
    });
});

describe('Budget values', function () {
    it('writes the first value dated today when a budget is created', function () {
        $user = makeUser();

        $budgetId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget', ['title' => 'Salary', 'type' => 'income', 'value' => 2500])
            ->assertStatus(201)
            ->json('data.id');

        $values = BudgetValue::where('budget_id', $budgetId)->get();
        expect($values)->toHaveCount(1);
        expect($values->first()->value)->toBe(2500.0);
        expect($values->first()->date->toDateString())->toBe(today()->toDateString());
    });

    it('records the value of the legacy budget update as today\'s value', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->withValue(100)->create();
        BudgetValue::factory()->forBudget($budget)->today()->withValue(100)->create();
        BudgetValue::factory()->forBudget($budget)->historical(30)->withValue(50)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budget->id, ['value' => 3000])
            ->assertStatus(200)
            ->assertJsonPath('data.value', 3000);

        expect(BudgetValue::where('budget_id', $budget->id)->count())->toBe(2);
        expect($budget->fresh()->value)->toBe(3000.0);
    });

    it('keeps the current value equal to the latest-dated value', function () {
        $user = makeUser();
        $budgetId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget', ['title' => 'Rent', 'type' => 'expense', 'value' => 800])
            ->json('data.id');
        $current = fn () => Budget::find($budgetId)->value;

        $older = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget/'.$budgetId.'/values', [
                'value' => 700,
                'date' => today()->subMonths(2)->toDateString(),
            ])
            ->assertStatus(201)
            ->json('data.id');
        expect($current())->toBe(800.0);

        $todayValue = BudgetValue::where('budget_id', $budgetId)->whereKeyNot($older)->first();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budgetId.'/values/'.$todayValue->id, ['value' => 850])
            ->assertStatus(200);
        expect($current())->toBe(850.0);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budgetId.'/values/'.$todayValue->id, [
                'date' => today()->subMonths(3)->toDateString(),
            ])
            ->assertStatus(200);
        expect($current())->toBe(700.0);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/budget/'.$budgetId.'/values/'.$older)
            ->assertStatus(200);
        expect($current())->toBe(850.0);
    });

    it('replaces the value of the same day', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->create();
        BudgetValue::factory()->forBudget($budget)->historical(3)->withValue(10)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget/'.$budget->id.'/values', [
                'value' => 20,
                'date' => today()->subDays(3)->toDateString(),
            ])
            ->assertStatus(201);

        expect(BudgetValue::where('budget_id', $budget->id)->pluck('value')->all())->toBe([20.0]);
        expect($budget->fresh()->value)->toBe(20.0);
    });

    it('rejects a date in the future and a date that already has a value', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->create();
        $value = BudgetValue::factory()->forBudget($budget)->historical(3)->create();
        BudgetValue::factory()->forBudget($budget)->historical(5)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget/'.$budget->id.'/values', [
                'value' => 20,
                'date' => today()->addDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budget->id.'/values/'.$value->id, [
                'date' => today()->subDays(5)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    });

    it('refuses to delete the last value of a budget item', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->withValue(100)->create();
        $value = BudgetValue::factory()->forBudget($budget)->withValue(100)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/budget/'.$budget->id.'/values/'.$value->id)
            ->assertStatus(422);

        expect(BudgetValue::count())->toBe(1);
        expect($budget->fresh()->value)->toBe(100.0);
    });

    it('lists the values ordered by date', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->create();
        BudgetValue::factory()->forBudget($budget)->today()->withValue(3)->create();
        BudgetValue::factory()->forBudget($budget)->historical(60)->withValue(1)->create();
        BudgetValue::factory()->forBudget($budget)->historical(30)->withValue(2)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget/'.$budget->id.'/values')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.value', 1)
            ->assertJsonPath('data.1.value', 2)
            ->assertJsonPath('data.2.value', 3);
    });

    it('forbids reading and changing values of another user', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser(makeUser())->withValue(10)->create();
        $value = BudgetValue::factory()->forBudget($budget)->withValue(10)->create();
        BudgetValue::factory()->forBudget($budget)->historical(400)->create();
        $base = '/api/v1/finances/budget/'.$budget->id.'/values';

        $this->actingAs($user, 'sanctum')->getJson($base)->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson($base, ['value' => 1])->assertStatus(403);
        $this->actingAs($user, 'sanctum')->putJson($base.'/'.$value->id, ['value' => 1])->assertStatus(403);
        $this->actingAs($user, 'sanctum')->deleteJson($base.'/'.$value->id)->assertStatus(403);

        expect(BudgetValue::count())->toBe(2);
        expect($value->fresh()->value)->toBe(10.0);
    });

    it('rejects a value that belongs to another budget', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->create();
        BudgetValue::factory()->forBudget($budget)->create();
        $foreign = BudgetValue::factory()->forBudget(Budget::factory()->forUser(makeUser())->create())->create();
        $base = '/api/v1/finances/budget/'.$budget->id.'/values/'.$foreign->id;

        $this->actingAs($user, 'sanctum')->putJson($base, ['value' => 1])->assertStatus(403);
        $this->actingAs($user, 'sanctum')->deleteJson($base)->assertStatus(403);

        expect($foreign->fresh())->not->toBeNull();
    });

    it('keeps value in list and show and leaves the history out', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->withValue(42.5)->create();
        BudgetValue::factory()->forBudget($budget)->withValue(42.5)->create();

        $list = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget')
            ->assertStatus(200)
            ->assertJsonPath('data.0.value', 42.5);
        expect($list->json('data.0'))->not->toHaveKey('values');

        $show = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget/'.$budget->id)
            ->assertStatus(200)
            ->assertJsonPath('data.value', 42.5);
        expect($show->json('data'))->not->toHaveKey('values');
    });

    it('invalidates the cached budget list on every value change', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->withValue(10)->create();
        $value = BudgetValue::factory()->forBudget($budget)->today()->withValue(10)->create();
        $older = BudgetValue::factory()->forBudget($budget)->historical(10)->withValue(5)->create();

        $list = fn () => $this->actingAs($user, 'sanctum')->getJson('/api/v1/finances/budget');

        $list()->assertJsonPath('data.0.value', 10);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget/'.$budget->id.'/values', ['value' => 20]);
        $list()->assertJsonPath('data.0.value', 20);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budget->id.'/values/'.$older->id, ['value' => 7]);
        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/budget/'.$budget->id.'/values/'.$value->id);
        $list()->assertJsonPath('data.0.value', 7);
    });
});

describe('WealthService', function () {
    it('storeValue replaces the value of the same day', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();
        $service = app(WealthService::class);

        $first = $service->storeValue($field, ['value' => 99.99]);
        $second = $service->storeValue($field, ['value' => 11.5]);

        expect($second->id)->toBe($first->id);
        expect($second->field_id)->toBe($field->id);
        expect($second->value)->toBe(11.5);
        expect(WealthValue::where('field_id', $field->id)->count())->toBe(1);
    });
});
