<?php

use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\BudgetValue;
use App\Api\Finances\Models\WealthField;
use App\Api\Finances\Models\WealthValue;
use App\Api\Tags\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

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

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/budget/'.$budget->id), 403);
    });

    it('lists budgets from highest to lowest value', function () {
        $user = makeUser();
        Budget::factory()->forUser($user)->create(['title' => 'Side job', 'value' => 400]);
        Budget::factory()->forUser($user)->create(['title' => 'Salary', 'value' => 2500]);
        Budget::factory()->forUser($user)->create(['title' => 'Interest', 'value' => 15]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget')
            ->assertStatus(200)
            ->assertJsonPath('data.*.title', ['Salary', 'Side job', 'Interest']);
    });
});

describe('Budget interval', function () {
    it('defaults the interval to monthly when none is given', function () {
        $user = makeUser();

        $budgetId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget', ['title' => 'Rent', 'type' => 'expense', 'value' => 800])
            ->assertStatus(201)
            ->assertJsonPath('data.interval', 'monthly')
            ->json('data.id');

        expect(Budget::find($budgetId)->interval)->toBe('monthly');
    });

    it('stores a quarterly or yearly interval', function (string $interval) {
        $user = makeUser();

        $budgetId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget', [
                'title' => 'Insurance',
                'type' => 'expense',
                'interval' => $interval,
                'value' => 300,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.interval', $interval)
            ->json('data.id');

        expect(Budget::find($budgetId)->interval)->toBe($interval);
    })->with(['quarterly', 'yearly']);

    it('updates the interval without touching the value', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->withValue(1200)->create();
        BudgetValue::factory()->forBudget($budget)->withValue(1200)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budget->id, ['interval' => 'yearly'])
            ->assertStatus(200)
            ->assertJsonPath('data.interval', 'yearly');

        $budget->refresh();
        expect($budget->interval)->toBe('yearly');
        expect($budget->value)->toBe(1200.0);
        expect(BudgetValue::where('budget_id', $budget->id)->count())->toBe(1);
    });

    it('rejects an unknown interval', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget', [
                'title' => 'Groceries',
                'type' => 'expense',
                'interval' => 'weekly',
                'value' => 50,
            ]);
        expectApiError($response, 422);
        $response->assertJsonValidationErrors(['interval']);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budget->id, ['interval' => 'weekly']);
        expectApiError($response, 422);
        $response->assertJsonValidationErrors(['interval']);

        expect($budget->fresh()->interval)->toBe('monthly');
    });

    it('includes the interval when listing and showing budgets', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->create(['interval' => 'quarterly']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget')
            ->assertStatus(200)
            ->assertJsonPath('data.0.interval', 'quarterly');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget/'.$budget->id)
            ->assertStatus(200)
            ->assertJsonPath('data.interval', 'quarterly');
    });
});

describe('Budget tags', function () {
    it('stores a budget with the user\'s tags and returns them', function () {
        $user = makeUser();
        $tag = Tag::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget', [
                'title' => 'Donations',
                'type' => 'income',
                'value' => 50,
                'tags' => [$tag->id],
            ])
            ->assertStatus(201)
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $tag->id);
    });

    it('replaces the tags on update', function () {
        $user = makeUser();
        $old = Tag::factory()->forUser($user)->create();
        $new = Tag::factory()->forUser($user)->create();
        $budget = Budget::factory()->forUser($user)->create();
        $budget->tags()->attach($old);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budget->id, ['tags' => [$new->id]])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $new->id);

        expect($budget->tags()->pluck('tags.id')->all())->toBe([$new->id]);
    });

    it('removes all tags when updated with an empty list', function () {
        $user = makeUser();
        $tag = Tag::factory()->forUser($user)->create();
        $budget = Budget::factory()->forUser($user)->create();
        $budget->tags()->attach($tag);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budget->id, ['tags' => []])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.tags');

        expect($budget->tags()->count())->toBe(0);
    });

    it('keeps the tags when an update does not send them', function () {
        $user = makeUser();
        $tag = Tag::factory()->forUser($user)->create();
        $budget = Budget::factory()->forUser($user)->create();
        $budget->tags()->attach($tag);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budget->id, ['title' => 'Renamed'])
            ->assertStatus(200)
            ->assertJsonPath('data.tags.0.id', $tag->id);

        expect($budget->tags()->count())->toBe(1);
    });

    it('rejects another user\'s tag on store and update', function () {
        $user = makeUser();
        $foreignTag = Tag::factory()->forUser(makeUser())->create();
        $budget = Budget::factory()->forUser($user)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget', [
                'title' => 'Donations',
                'type' => 'income',
                'value' => 50,
                'tags' => [$foreignTag->id],
            ]);
        expectApiError($response, 422);
        $response->assertJsonValidationErrors('tags.0');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budget->id, ['tags' => [$foreignTag->id]]);
        expectApiError($response, 422);
        $response->assertJsonValidationErrors('tags.0');

        expect(Budget::count())->toBe(1)
            ->and($budget->tags()->count())->toBe(0);
    });

    it('removes a deleted tag from the budget', function () {
        $user = makeUser();
        $tag = Tag::factory()->forUser($user)->create();
        $budget = Budget::factory()->forUser($user)->create();
        $budget->tags()->attach($tag);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tags/'.$tag->id)
            ->assertStatus(200);

        expect($budget->tags()->count())->toBe(0)
            ->and(DB::table('tag_budget')->count())->toBe(0);
    });

    it('removes the pivot rows when the budget is deleted', function () {
        $user = makeUser();
        $tag = Tag::factory()->forUser($user)->create();
        $budget = Budget::factory()->forUser($user)->create();
        $budget->tags()->attach($tag);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/budget/'.$budget->id)
            ->assertStatus(200);

        expect(DB::table('tag_budget')->count())->toBe(0)
            ->and(Tag::count())->toBe(1);
    });

    it('returns the tags in the index and show', function () {
        $user = makeUser();
        $tag = Tag::factory()->forUser($user)->create();
        $budget = Budget::factory()->forUser($user)->create();
        $budget->tags()->attach($tag);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget')
            ->assertStatus(200)
            ->assertJsonPath('data.0.tags.0.id', $tag->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/budget/'.$budget->id)
            ->assertStatus(200)
            ->assertJsonPath('data.tags.0.id', $tag->id);
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

    it('lists fields from highest to lowest current value, empty fields last', function () {
        $user = makeUser();
        WealthField::factory()->forUser($user)->create(['title' => 'Empty']);
        $debt = WealthField::factory()->forUser($user)->create(['title' => 'Debt']);
        $savings = WealthField::factory()->forUser($user)->create(['title' => 'Savings']);
        $stocks = WealthField::factory()->forUser($user)->create(['title' => 'Stocks']);

        WealthValue::factory()->forField($debt)->today()->withValue(-500)->create();
        WealthValue::factory()->forField($savings)->today()->withValue(1000)->create();
        // An older, higher value must not count: only the latest-dated value decides.
        WealthValue::factory()->forField($stocks)->historical(30)->withValue(9000)->create();
        WealthValue::factory()->forField($stocks)->today()->withValue(200)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/finances/wealth/fields')
            ->assertStatus(200)
            ->assertJsonPath('data.*.title', ['Savings', 'Stocks', 'Debt', 'Empty']);
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

    it('updates today\'s value in place when no date is given', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();
        $url = '/api/v1/finances/wealth/fields/'.$field->id.'/value';

        $this->actingAs($user, 'sanctum')->putJson($url, ['value' => 100])->assertStatus(200);
        $first = WealthValue::where('field_id', $field->id)->sole();
        $this->actingAs($user, 'sanctum')->putJson($url, ['value' => 200])->assertStatus(200);

        $values = WealthValue::where('field_id', $field->id)->get();
        expect($values)->toHaveCount(1)
            ->and($values->first()->id)->toBe($first->id)
            ->and($values->first()->value)->toBe(200.0);
    });

    it('rejects a date in the future', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', [
                'value' => 10,
                'date' => today()->addDay()->toDateString(),
            ]);
        expectApiError($response, 422);
        $response->assertJsonValidationErrors(['date']);
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

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$value->id, [
                'date' => today()->subDays(7)->toDateString(),
            ]);
        expectApiError($response, 422);
        $response->assertJsonValidationErrors(['date']);

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

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/value', ['value' => 1]), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$value->id, ['value' => 1]), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$value->id), 403);

        expect(WealthValue::count())->toBe(1);
        expect($value->fresh()->value)->toBe(10.0);
    });

    it('rejects a value that belongs to another field', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();
        $foreignValue = WealthValue::factory()->forField(WealthField::factory()->forUser(makeUser())->create())->create();

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$foreignValue->id, ['value' => 1]), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/'.$foreignValue->id), 403);

        expect(WealthValue::count())->toBe(1);
    });

    it('returns 404 for a value that does not exist', function () {
        $user = makeUser();
        $field = WealthField::factory()->forUser($user)->create();

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/wealth/fields/'.$field->id.'/values/999999'), 404);
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

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/finances/budget/'.$budget->id.'/values', [
                'value' => 20,
                'date' => today()->addDay()->toDateString(),
            ]);
        expectApiError($response, 422);
        $response->assertJsonValidationErrors(['date']);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/finances/budget/'.$budget->id.'/values/'.$value->id, [
                'date' => today()->subDays(5)->toDateString(),
            ]);
        expectApiError($response, 422);
        $response->assertJsonValidationErrors(['date']);
    });

    it('refuses to delete the last value of a budget item', function () {
        Exceptions::fake();
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->withValue(100)->create();
        $value = BudgetValue::factory()->forBudget($budget)->withValue(100)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/finances/budget/'.$budget->id.'/values/'.$value->id);
        expectApiError($response, 422);
        $response->assertJsonPath('message', 'A budget item needs at least one value.')
            ->assertJsonMissingPath('errors')
            ->assertJsonMissingPath('meta');

        expect(BudgetValue::count())->toBe(1);
        expect($budget->fresh()->value)->toBe(100.0);
        Exceptions::assertNothingReported();
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

        expectApiError($this->actingAs($user, 'sanctum')->getJson($base), 403);
        expectApiError($this->actingAs($user, 'sanctum')->postJson($base, ['value' => 1]), 403);
        expectApiError($this->actingAs($user, 'sanctum')->putJson($base.'/'.$value->id, ['value' => 1]), 403);
        expectApiError($this->actingAs($user, 'sanctum')->deleteJson($base.'/'.$value->id), 403);

        expect(BudgetValue::count())->toBe(2);
        expect($value->fresh()->value)->toBe(10.0);
    });

    it('rejects a value that belongs to another budget', function () {
        $user = makeUser();
        $budget = Budget::factory()->forUser($user)->create();
        BudgetValue::factory()->forBudget($budget)->create();
        $foreign = BudgetValue::factory()->forBudget(Budget::factory()->forUser(makeUser())->create())->create();
        $base = '/api/v1/finances/budget/'.$budget->id.'/values/'.$foreign->id;

        expectApiError($this->actingAs($user, 'sanctum')->putJson($base, ['value' => 1]), 403);
        expectApiError($this->actingAs($user, 'sanctum')->deleteJson($base), 403);

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
