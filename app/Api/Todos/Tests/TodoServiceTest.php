<?php

use App\Api\Todos\Models\Todo;
use App\Api\Todos\Services\TodoService;

describe('due', function () {
    beforeEach(function () {
        $this->user = makeUser();
        $this->service = app(TodoService::class);
    });

    it('returns open todos due on the date or earlier', function () {
        Todo::factory()->forUser($this->user)->create(['title' => 'On the date', 'is_completed' => false, 'due_at' => '2026-08-14']);
        Todo::factory()->forUser($this->user)->create(['title' => 'Earlier', 'is_completed' => false, 'due_at' => '2026-08-01']);
        Todo::factory()->forUser($this->user)->create(['title' => 'Later', 'is_completed' => false, 'due_at' => '2026-08-15']);
        Todo::factory()->forUser($this->user)->create(['title' => 'No date', 'is_completed' => false, 'due_at' => null]);
        Todo::factory()->forUser($this->user)->create(['title' => 'Completed', 'is_completed' => true, 'due_at' => '2026-08-14']);
        Todo::factory()->forUser(makeUser())->create(['title' => 'Foreign', 'is_completed' => false, 'due_at' => '2026-08-14']);

        $titles = $this->service->due($this->user, '2026-08-14')->pluck('title')->sort()->values()->all();

        expect($titles)->toBe(['Earlier', 'On the date']);
    });

    it('orders by due date and applies the limit', function () {
        Todo::factory()->forUser($this->user)->create(['title' => 'Middle', 'is_completed' => false, 'due_at' => '2026-08-10']);
        Todo::factory()->forUser($this->user)->create(['title' => 'Newest', 'is_completed' => false, 'due_at' => '2026-08-14']);
        Todo::factory()->forUser($this->user)->create(['title' => 'Oldest', 'is_completed' => false, 'due_at' => '2026-08-01']);

        $titles = $this->service->due($this->user, '2026-08-14', 2)->pluck('title')->all();

        expect($titles)->toBe(['Oldest', 'Middle']);
    });
});
