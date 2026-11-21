<?php

use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\TimeTracking\Services\TimeTrackingService;

beforeEach(function () {
    $this->user = makeUser();
    $this->service = app(TimeTrackingService::class);
});

describe('runningEntry', function () {
    it('returns the user\'s running entry with its project', function () {
        $project = TimeTrackingProject::factory()->forUser($this->user)->create();
        TimeTrackingEntry::factory()->forUser($this->user)->forProject($project)->create();
        $running = TimeTrackingEntry::factory()->forUser($this->user)->forProject($project)->running()->create();
        TimeTrackingEntry::factory()->forUser(makeUser())->running()->create();

        $entry = $this->service->runningEntry($this->user);

        expect($entry->id)->toBe($running->id)
            ->and($entry->relationLoaded('project'))->toBeTrue();
    });

    it('returns null without a running entry', function () {
        TimeTrackingEntry::factory()->forUser($this->user)->create();

        expect($this->service->runningEntry($this->user))->toBeNull();
    });
});

describe('projectsByLastUse', function () {
    it('orders by the latest entry start, then never used projects by title', function () {
        $old = TimeTrackingProject::factory()->forUser($this->user)->create(['title' => 'Old']);
        $recent = TimeTrackingProject::factory()->forUser($this->user)->create(['title' => 'Recent']);
        TimeTrackingProject::factory()->forUser($this->user)->create(['title' => 'Zeta']);
        TimeTrackingProject::factory()->forUser($this->user)->create(['title' => 'Alpha']);
        TimeTrackingProject::factory()->forUser(makeUser())->create(['title' => 'Foreign']);
        TimeTrackingEntry::factory()->forUser($this->user)->forProject($old)->create(['started_at' => now()->subDays(5)]);
        TimeTrackingEntry::factory()->forUser($this->user)->forProject($recent)->create(['started_at' => now()->subDay()]);

        $titles = $this->service->projectsByLastUse($this->user, 10)->pluck('title')->all();

        expect($titles)->toBe(['Recent', 'Old', 'Alpha', 'Zeta']);
    });

    it('applies the limit and leaves out the excepted project', function () {
        $first = TimeTrackingProject::factory()->forUser($this->user)->create(['title' => 'A']);
        TimeTrackingProject::factory()->forUser($this->user)->create(['title' => 'B']);
        TimeTrackingProject::factory()->forUser($this->user)->create(['title' => 'C']);

        $titles = $this->service->projectsByLastUse($this->user, 1, $first->id)->pluck('title')->all();

        expect($titles)->toBe(['B']);
    });
});

describe('switchTo', function () {
    it('stops other running timers and starts the project', function () {
        $other = TimeTrackingProject::factory()->forUser($this->user)->create();
        $target = TimeTrackingProject::factory()->forUser($this->user)->create();
        $otherEntry = TimeTrackingEntry::factory()->forUser($this->user)->forProject($other)->running()->create(['started_at' => now()->subHour()]);

        $this->service->switchTo($this->user, $target);

        expect($otherEntry->fresh()->stopped_at)->not->toBeNull()
            ->and(TimeTrackingEntry::forUser($this->user->id)->whereNull('stopped_at')->pluck('project_id')->all())->toBe([$target->id]);
    });

    it('is a no-op while the project\'s timer runs', function () {
        $project = TimeTrackingProject::factory()->forUser($this->user)->create();
        $entry = TimeTrackingEntry::factory()->forUser($this->user)->forProject($project)->running()->create();

        $this->service->switchTo($this->user, $project);

        expect($entry->fresh()->stopped_at)->toBeNull()
            ->and(TimeTrackingEntry::forUser($this->user->id)->count())->toBe(1);
    });
});
