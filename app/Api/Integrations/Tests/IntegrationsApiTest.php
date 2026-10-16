<?php

describe('GET /api/v1/integrations', function () {
    it('reports an integration as configured when its config is set', function (string $integration, array $config) {
        config($config);

        $response = $this->actingAs(makeUser(), 'sanctum')->getJson('/api/v1/integrations');

        expectApiSuccess($response);
        $response->assertJsonPath("data.{$integration}", true);
    })->with([
        'hardcover' => ['hardcover', ['services.hardcover.api_key' => 'fake-hardcover-key']],
        'tmdb' => ['tmdb', ['services.tmdb.access_token' => 'fake-tmdb-token']],
        'discogs' => ['discogs', ['services.discogs.access_token' => 'fake-discogs-token']],
        'bgg' => ['bgg', ['services.bgg.api_key' => 'fake-bgg-key']],
        'spotify' => ['spotify', ['services.spotify.client_id' => 'fake-spotify-id', 'services.spotify.client_secret' => 'fake-spotify-secret']],
        'telegram' => ['telegram', ['telegram.bots.solyto.telegram_token' => 'fake-telegram-token', 'telegram.bots.solyto.webhook_token' => 'fake-webhook-token']],
        'ntfy' => ['ntfy', ['services.ntfy.url' => 'https://ntfy.example.test', 'services.ntfy.topic' => 'fake-topic']],
        'ntfy with only a per-type topic' => ['ntfy', ['services.ntfy.url' => 'https://ntfy.example.test', 'services.ntfy.topic' => null, 'services.ntfy.topics.traffic' => 'fake-topic']],
    ]);

    it('reports an integration as not configured when its config is missing', function (string $integration, array $config) {
        config($config);

        $response = $this->actingAs(makeUser(), 'sanctum')->getJson('/api/v1/integrations');

        expectApiSuccess($response);
        $response->assertJsonPath("data.{$integration}", false);
    })->with([
        'hardcover' => ['hardcover', ['services.hardcover.api_key' => null]],
        'tmdb' => ['tmdb', ['services.tmdb.access_token' => '']],
        'discogs' => ['discogs', ['services.discogs.access_token' => null]],
        'bgg' => ['bgg', ['services.bgg.api_key' => '']],
        'spotify without client secret' => ['spotify', ['services.spotify.client_id' => 'fake-spotify-id', 'services.spotify.client_secret' => null]],
        'spotify without client id' => ['spotify', ['services.spotify.client_id' => '', 'services.spotify.client_secret' => 'fake-spotify-secret']],
        'telegram without webhook token' => ['telegram', ['telegram.bots.solyto.telegram_token' => 'fake-telegram-token', 'telegram.bots.solyto.webhook_token' => null]],
        'telegram without bot token' => ['telegram', ['telegram.bots.solyto.telegram_token' => '', 'telegram.bots.solyto.webhook_token' => 'fake-webhook-token']],
        'ntfy without topic' => ['ntfy', ['services.ntfy.url' => 'https://ntfy.example.test', 'services.ntfy.topic' => null, 'services.ntfy.topics' => ['registrations' => null, 'dev_requests' => '', 'traffic' => null]]],
        'ntfy without url' => ['ntfy', ['services.ntfy.url' => '', 'services.ntfy.topic' => 'fake-topic']],
    ]);

    it('returns only booleans for exactly the known integrations and never config values', function () {
        config([
            'services.hardcover.api_key' => 'fake-hardcover-key',
            'services.tmdb.access_token' => 'fake-tmdb-token',
            'services.discogs.access_token' => null,
            'services.bgg.api_key' => 'fake-bgg-key',
            'services.spotify.client_id' => 'fake-spotify-id',
            'services.spotify.client_secret' => null,
            'telegram.bots.solyto.telegram_token' => 'fake-telegram-token',
            'telegram.bots.solyto.webhook_token' => 'fake-webhook-token',
            'services.ntfy.url' => 'https://ntfy.example.test',
            'services.ntfy.topic' => null,
        ]);

        $response = $this->actingAs(makeUser(), 'sanctum')->getJson('/api/v1/integrations');

        expectApiSuccess($response);
        $data = $response->json('data');

        expect(array_keys($data))->toBe(['hardcover', 'tmdb', 'discogs', 'bgg', 'spotify', 'telegram', 'ntfy']);
        expect($data)->each->toBeBool();
        expect($data)->toBe([
            'hardcover' => true,
            'tmdb' => true,
            'discogs' => false,
            'bgg' => true,
            'spotify' => false,
            'telegram' => true,
            'ntfy' => false,
        ]);

        $body = $response->getContent();
        foreach (['fake-hardcover-key', 'fake-tmdb-token', 'fake-bgg-key', 'fake-spotify-id', 'fake-telegram-token', 'fake-webhook-token', 'ntfy.example.test'] as $secret) {
            expect($body)->not->toContain($secret);
        }
    });

    it('requires authentication', function () {
        expectApiError($this->getJson('/api/v1/integrations'), 401);
    });
});
