<?php

use App\Api\Users\Models\User;
use App\Plugins\Ai\Services\AiModelSnapshot;
use App\Plugins\Ai\Services\AiService;
use App\Plugins\Models\UserPlugin;
use App\Plugins\Services\UserPluginService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->app->instance(AiModelSnapshot::class, new AiModelSnapshot(base_path('tests/Fixtures/ai-models.json')));
});

/**
 * Build a fake chat completion response.
 */
function fakeChatResponse(string $content, array $usage = ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]): \OpenAI\Responses\Chat\CreateResponse
{
    return \OpenAI\Responses\Chat\CreateResponse::from([
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'created' => time(),
        'model' => 'gpt-4o-mini',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'stop',
        ]],
        'usage' => $usage,
    ], \OpenAI\Responses\Meta\MetaInformation::from([]));
}

/**
 * Build a mocked OpenAI client that returns the given responses for chat()->create().
 */
function mockedOpenAiClient($response): \OpenAI\Contracts\ClientContract
{
    $chat = Mockery::mock(\OpenAI\Contracts\Resources\ChatContract::class);
    $chat->shouldReceive('create')->andReturn($response);

    $client = Mockery::mock(\OpenAI\Contracts\ClientContract::class);
    $client->shouldReceive('chat')->andReturn($chat);

    return $client;
}

/**
 * Build an AiService backed by the given OpenAI client or PSR-18 HTTP client.
 */
function makeAiService(?\OpenAI\Contracts\ClientContract $client = null, ?\Psr\Http\Client\ClientInterface $httpClient = null): AiService
{
    return new AiService(app(UserPluginService::class), $client, $httpClient);
}

/**
 * An OpenAI client that fails the test if any request is made.
 */
function unusedOpenAiClient(): \OpenAI\Contracts\ClientContract
{
    $client = Mockery::mock(\OpenAI\Contracts\ClientContract::class);
    $client->shouldNotReceive('chat');

    return $client;
}

describe('AiService', function () {
    beforeEach(function () {
        $this->user = makeUser();
        UserPlugin::factory()->forUser($this->user)->create(['settings' => ['provider' => 'openai', 'model' => 'gpt-a']]);
    });

    it('respond returns the chat message content', function () {
        $service = makeAiService(mockedOpenAiClient(fakeChatResponse('Hello there!')));

        $result = $service->respond($this->user, 'You are helpful', 'Hi');

        expect($result)->toBe('Hello there!');
    });

    it('respond handles an array of messages', function () {
        $service = makeAiService(mockedOpenAiClient(fakeChatResponse('ok')));

        $result = $service->respond($this->user, 'System', [
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => 'two'],
        ]);

        expect($result)->toBe('ok');
    });

    it('respondStructured decodes the json response', function () {
        $service = makeAiService(mockedOpenAiClient(fakeChatResponse(json_encode(['recommendations' => []]))));

        $result = $service->respondStructured($this->user, 'Prompt', 'input', ['type' => 'json_schema']);

        expect($result)->toBe(['recommendations' => []]);
    });
});

describe('AiService client configuration', function () {
    it('sends the request to the user\'s provider with their key and model', function () {
        $user = makeUser();
        UserPlugin::factory()->forUser($user)->create([
            'credentials' => ['api_key' => 'sk-or-user-key'],
            'settings' => ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet'],
        ]);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'anthropic/claude-sonnet',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => '{"recommendations":[]}'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ])),
        ]));
        $stack->push(Middleware::history($history));

        $result = makeAiService(httpClient: new GuzzleClient(['handler' => $stack]))
            ->respondStructured($user, 'Prompt', 'input', ['type' => 'json_object']);

        expect($result)->toBe(['recommendations' => []])
            ->and($history)->toHaveCount(1);

        $request = $history[0]['request'];
        expect((string) $request->getUri())->toBe('https://openrouter.ai/api/v1/chat/completions')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer sk-or-user-key')
            ->and(json_decode((string) $request->getBody(), true)['model'])->toBe('anthropic/claude-sonnet');
    });
});

describe('AiService when the AI plugin is unavailable', function () {
    it('makes no request and returns null', function (Closure $setup) {
        $user = makeUser();
        $setup($user);

        $service = makeAiService(unusedOpenAiClient());

        expect($service->respondStructured($user, 'Prompt', 'input', ['type' => 'json_schema']))->toBeNull()
            ->and($service->respond($user, 'Prompt', 'input'))->toBeNull();
    })->with([
        'no plugin row' => [fn () => fn (User $user) => null],
        'plugin disabled' => [fn () => fn (User $user) => UserPlugin::factory()->forUser($user)->create(['enabled' => false])],
        'no api key' => [fn () => fn (User $user) => UserPlugin::factory()->forUser($user)->create(['credentials' => null])],
        'empty api key' => [fn () => fn (User $user) => UserPlugin::factory()->forUser($user)->create(['credentials' => ['api_key' => '']])],
        'unreadable api key' => [fn () => function (User $user) {
            $plugin = UserPlugin::factory()->forUser($user)->create();
            $foreign = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');
            DB::table('user_plugins')->where('id', $plugin->id)
                ->update(['credentials' => $foreign->encrypt(json_encode(['api_key' => 'sk-lost']), false)]);
        }],
        'model not in the snapshot' => [fn () => fn (User $user) => UserPlugin::factory()->forUser($user)->create([
            'settings' => ['provider' => 'openai', 'model' => 'gpt-retired'],
        ])],
    ]);
});
