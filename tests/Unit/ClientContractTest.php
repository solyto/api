<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Client contract', function () {
    // Since 1.0.15. solyto/app src/routes/setup/+page.svelte:37
    it('keeps app and version at the top level of health', function () {
        $response = $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('app', 'solyto');

        expect($response->json('version'))->toBeString()->not->toBeEmpty();
    });

    it('keeps the standard envelope on health', function () {
        $response = $this->getJson('/api/v1/health')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'OK')
            ->assertJsonPath('data.app', 'solyto');

        expect($response->json('data.version'))->toBeString()->not->toBeEmpty()
            ->and($response->json('data.version'))->toBe($response->json('version'));
    });
});
