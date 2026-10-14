<?php

use App\Plugins\Ai\Services\AiModelSnapshot;
use App\Plugins\Enums\PluginEnum;
use App\Plugins\Models\UserPlugin;
use App\Shared\Services\UserCacheService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

const PLUGIN_TEST_KEY = 'sk-test-secret-key-1234567890';

beforeEach(function () {
    $this->app->instance(AiModelSnapshot::class, new AiModelSnapshot(base_path('tests/Fixtures/ai-models.json')));
});

function aiPayload(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'api_key' => PLUGIN_TEST_KEY,
        'provider' => 'openai',
        'model' => 'gpt-a',
    ], $overrides);
}

describe('GET /api/v1/users/me/plugins', function () {
    it('lists every plugin with defaults when nothing is stored', function () {
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/users/me/plugins');

        expectApiSuccess($response);
        $response->assertJsonCount(count(PluginEnum::cases()), 'data')
            ->assertJsonPath('data.0.plugin', 'ai')
            ->assertJsonPath('data.0.enabled', false)
            ->assertJsonPath('data.0.has_api_key', false)
            ->assertJsonPath('data.0.settings', null)
            ->assertJsonPath('data.0.model_available', false);
    });

    it('shows the stored state in the same shape as the single endpoint', function () {
        $user = makeUser();
        UserPlugin::factory()->forUser($user)->create([
            'credentials' => ['api_key' => PLUGIN_TEST_KEY],
            'settings' => ['provider' => 'openai', 'model' => 'gpt-b'],
        ]);

        $list = $this->actingAs($user, 'sanctum')->getJson('/api/v1/users/me/plugins');
        $single = $this->actingAs($user, 'sanctum')->getJson('/api/v1/users/me/plugins/ai');

        expect($list->json('data.0'))->toBe($single->json('data'))
            ->and($single->json('data'))->toBe([
                'plugin' => 'ai',
                'enabled' => true,
                'has_api_key' => true,
                'settings' => ['provider' => 'openai', 'model' => 'gpt-b'],
                'model_available' => true,
            ]);
    });

    it('requires authentication', function () {
        expectApiError($this->getJson('/api/v1/users/me/plugins'), 401);
        expectApiError($this->getJson('/api/v1/users/me/plugins/ai'), 401);
        expectApiError($this->putJson('/api/v1/users/me/plugins/ai', aiPayload()), 401);
        expectApiError($this->getJson('/api/v1/plugins/ai/providers'), 401);
    });
});

describe('GET /api/v1/users/me/plugins/{plugin}', function () {
    it('returns 404 for an unknown plugin', function () {
        $user = makeUser();

        expectApiError($this->actingAs($user, 'sanctum')->getJson('/api/v1/users/me/plugins/unknown'), 404);
        expectApiError($this->actingAs($user, 'sanctum')->putJson('/api/v1/users/me/plugins/unknown', aiPayload()), 404);
    });

    it('reports a stored model that is not in the snapshot as unavailable and keeps it', function () {
        $user = makeUser();
        UserPlugin::factory()->forUser($user)->create([
            'settings' => ['provider' => 'openai', 'model' => 'gpt-retired'],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/users/me/plugins/ai');

        expectApiSuccess($response);
        $response->assertJsonPath('data.model_available', false)
            ->assertJsonPath('data.settings.model', 'gpt-retired');
        expect(UserPlugin::forUser($user->id)->first()->settings['model'])->toBe('gpt-retired');
    });

    it('treats a key encrypted under another APP_KEY as not configured', function () {
        $user = makeUser();
        $plugin = UserPlugin::factory()->forUser($user)->create();
        $foreign = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');
        DB::table('user_plugins')->where('id', $plugin->id)
            ->update(['credentials' => $foreign->encrypt(json_encode(['api_key' => PLUGIN_TEST_KEY]), false)]);

        $show = $this->actingAs($user, 'sanctum')->getJson('/api/v1/users/me/plugins/ai');
        $index = $this->actingAs($user, 'sanctum')->getJson('/api/v1/users/me/plugins');

        expectApiSuccess($show);
        $show->assertJsonPath('data.has_api_key', false);
        $index->assertJsonPath('data.0.has_api_key', false);

        $withoutKey = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/plugins/ai', aiPayload(['api_key' => null]));
        expectApiError($withoutKey, 422);
        $withoutKey->assertJsonValidationErrors('api_key');

        $reentered = $this->actingAs($user, 'sanctum')->putJson('/api/v1/users/me/plugins/ai', aiPayload());
        expectApiSuccess($reentered);
        $reentered->assertJsonPath('data.has_api_key', true);
        expect($plugin->fresh()->credentials)->toBe(['api_key' => PLUGIN_TEST_KEY]);
    });
});

describe('PUT /api/v1/users/me/plugins/{plugin}', function () {
    it('creates the row and stores the key encrypted', function () {
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/v1/users/me/plugins/ai', aiPayload());

        expectApiSuccess($response);
        $response->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.has_api_key', true)
            ->assertJsonPath('data.settings', ['provider' => 'openai', 'model' => 'gpt-a'])
            ->assertJsonPath('data.model_available', true);

        $raw = DB::table('user_plugins')->where('user_id', $user->id)->value('credentials');
        expect($raw)->not->toContain(PLUGIN_TEST_KEY)
            ->and(json_decode(decrypt($raw, false), true))->toBe(['api_key' => PLUGIN_TEST_KEY]);
    });

    it('keeps the stored key when api_key is left out', function () {
        $user = makeUser();
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/users/me/plugins/ai', aiPayload());

        $payload = aiPayload(['enabled' => false, 'provider' => 'mistral', 'model' => 'mistral-small']);
        unset($payload['api_key']);
        $response = $this->actingAs($user, 'sanctum')->putJson('/api/v1/users/me/plugins/ai', $payload);

        expectApiSuccess($response);
        $response->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.has_api_key', true)
            ->assertJsonPath('data.settings.provider', 'mistral');
        expect(UserPlugin::forUser($user->id)->count())->toBe(1)
            ->and(UserPlugin::forUser($user->id)->first()->credentials)->toBe(['api_key' => PLUGIN_TEST_KEY]);
    });

    it('requires an api_key when none is stored yet', function () {
        $user = makeUser();
        $payload = aiPayload();
        unset($payload['api_key']);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/v1/users/me/plugins/ai', $payload);

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('api_key');
    });

    it('rejects an unknown provider', function () {
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/plugins/ai', aiPayload(['provider' => 'acme']));

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('provider');
    });

    it('rejects a model that is not in the snapshot for the provider', function (string $provider, string $model) {
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/plugins/ai', aiPayload(['provider' => $provider, 'model' => $model]));

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('model');
        expect(UserPlugin::forUser($user->id)->exists())->toBeFalse();
    })->with([
        'unknown model' => ['openai', 'gpt-unknown'],
        'model of another provider' => ['mistral', 'gpt-a'],
    ]);

    it('requires enabled, provider and model', function () {
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/plugins/ai', ['api_key' => PLUGIN_TEST_KEY]);

        expectApiError($response, 422);
        $response->assertJsonValidationErrors(['enabled', 'provider', 'model']);
    });
});

describe('GET /api/v1/plugins/ai/providers', function () {
    it('lists the providers with their snapshot models', function () {
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/plugins/ai/providers');

        expectApiSuccess($response);
        expect($response->json('data'))->toBe([
            ['provider' => 'openai', 'name' => 'OpenAI', 'models' => [['id' => 'gpt-a', 'name' => 'GPT A'], ['id' => 'gpt-b', 'name' => 'GPT B']]],
            ['provider' => 'openrouter', 'name' => 'OpenRouter', 'models' => [['id' => 'anthropic/claude-sonnet', 'name' => 'Claude Sonnet']]],
            ['provider' => 'mistral', 'name' => 'Mistral', 'models' => [['id' => 'mistral-small', 'name' => 'Mistral Small']]],
        ]);
    });
});

describe('User plugin secrecy and isolation', function () {
    it('never returns the api key', function () {
        $user = makeUser();
        $responses = [
            $this->actingAs($user, 'sanctum')->putJson('/api/v1/users/me/plugins/ai', aiPayload()),
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/users/me/plugins'),
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/users/me/plugins/ai'),
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/plugins/ai/providers'),
        ];

        foreach ($responses as $response) {
            expectApiSuccess($response);
            expect($response->getContent())->not->toContain(PLUGIN_TEST_KEY)
                ->and($response->getContent())->not->toContain('"api_key"')
                ->and($response->getContent())->not->toContain('"credentials"');
        }
    });

    it('does not show another user\'s plugin', function () {
        $owner = makeUser();
        $other = makeUser();
        UserPlugin::factory()->forUser($owner)->create();

        $show = $this->actingAs($other, 'sanctum')->getJson('/api/v1/users/me/plugins/ai');
        $index = $this->actingAs($other, 'sanctum')->getJson('/api/v1/users/me/plugins');

        $show->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.has_api_key', false)
            ->assertJsonPath('data.settings', null);
        $index->assertJsonPath('data.0.enabled', false)
            ->assertJsonPath('data.0.has_api_key', false);
    });

    it('does not let an update touch another user\'s row', function () {
        $owner = makeUser();
        $other = makeUser();
        $plugin = UserPlugin::factory()->forUser($owner)->create(['enabled' => true]);

        $this->actingAs($other, 'sanctum')
            ->putJson('/api/v1/users/me/plugins/ai', aiPayload(['enabled' => false, 'api_key' => 'sk-other']));

        expect($plugin->fresh()->enabled)->toBeTrue()
            ->and($plugin->fresh()->credentials)->not->toBe(['api_key' => 'sk-other'])
            ->and(UserPlugin::forUser($other->id)->count())->toBe(1);
    });

    it('deletes the rows with the user', function () {
        $user = makeUser();
        UserPlugin::factory()->forUser($user)->create();

        $user->delete();

        expect(DB::table('user_plugins')->where('user_id', $user->id)->count())->toBe(0);
    });
});

describe('AI plugin changes and recommendation caches', function () {
    it('forgets the cached book and music recommendations when the AI plugin is saved', function (array $payload) {
        $user = makeUser();
        $other = makeUser();
        UserPlugin::factory()->forUser($user)->create();
        $cache = app(UserCacheService::class);
        foreach ([$user, $other] as $owner) {
            $cache->store(['book_recommendations', $owner->id], 3600, [['title' => 'Old book']]);
            $cache->store(['music_recommendations', $owner->id], 3600, [['title' => 'Old album']]);
        }

        expectApiSuccess($this->actingAs($user, 'sanctum')->putJson('/api/v1/users/me/plugins/ai', aiPayload($payload)));

        expect($cache->get(['book_recommendations', $user->id]))->toBeNull()
            ->and($cache->get(['music_recommendations', $user->id]))->toBeNull()
            ->and($cache->get(['book_recommendations', $other->id]))->toBe([['title' => 'Old book']])
            ->and($cache->get(['music_recommendations', $other->id]))->toBe([['title' => 'Old album']]);
    })->with([
        'disabled' => [['enabled' => false]],
        'model changed' => [['model' => 'gpt-b']],
    ]);
});
