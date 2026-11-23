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
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use Psr\Http\Client\ClientInterface;

beforeEach(function () {
    $this->app->instance(AiModelSnapshot::class, new AiModelSnapshot(base_path('tests/Fixtures/ai-models.json')));
});

function fakeChatResponse(string $content, array $usage = ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]): CreateResponse
{
    return CreateResponse::from([
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
    ], MetaInformation::from([]));
}

function mockedOpenAiClient(CreateResponse $response): ClientContract
{
    $chat = Mockery::mock(ChatContract::class);
    $chat->shouldReceive('create')->andReturn($response);

    $client = Mockery::mock(ClientContract::class);
    $client->shouldReceive('chat')->andReturn($chat);

    return $client;
}

function makeAiService(?ClientContract $client = null, ?ClientInterface $httpClient = null): AiService
{
    return new AiService(app(UserPluginService::class), $client, $httpClient);
}

function unusedOpenAiClient(): ClientContract
{
    $client = Mockery::mock(ClientContract::class);
    $client->shouldNotReceive('chat');

    return $client;
}

describe('AiService', function () {
    beforeEach(function () {
        $this->user = makeUser();
        UserPlugin::factory()->forUser($this->user)->create(['settings' => ['provider' => 'openai', 'model' => 'gpt-a']]);
    });

    it('returns the chat message content', function () {
        $service = makeAiService(mockedOpenAiClient(fakeChatResponse('Hello there!')));

        $result = $service->respond($this->user, 'You are helpful', 'Hi');

        expect($result)->toBe('Hello there!');
    });

    it('sends a list of messages after the system prompt', function () {
        $conversation = [
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => 'two'],
        ];
        $chat = Mockery::mock(ChatContract::class);
        $chat->shouldReceive('create')
            ->once()
            ->withArgs(fn (array $request) => $request['messages'] === [['role' => 'system', 'content' => 'System'], ...$conversation])
            ->andReturn(fakeChatResponse('ok'));
        $client = Mockery::mock(ClientContract::class);
        $client->shouldReceive('chat')->andReturn($chat);

        expect(makeAiService($client)->respond($this->user, 'System', $conversation))->toBe('ok');
    });

    it('decodes a structured JSON answer', function () {
        $service = makeAiService(mockedOpenAiClient(fakeChatResponse(json_encode(['recommendations' => []]))));

        $result = $service->respondStructured($this->user, 'Prompt', 'input', ['type' => 'json_schema']);

        expect($result)->toBe(['recommendations' => []]);
    });

    it('gives no structured answer when the answer is not a JSON object', function (string $content) {
        $service = makeAiService(mockedOpenAiClient(fakeChatResponse($content)));

        expect($service->respondStructured($this->user, 'Prompt', 'input', ['type' => 'json_schema']))->toBeNull();
    })->with([
        'a number' => '42',
        'a string' => '"Sorry, I can\'t help with that."',
        'no json' => 'Here are some books you might like.',
    ]);
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
        'no plugin row' => [fn (User $user) => null],
        'plugin disabled' => [fn (User $user) => UserPlugin::factory()->forUser($user)->create(['enabled' => false])],
        'no api key' => [fn (User $user) => UserPlugin::factory()->forUser($user)->create(['credentials' => null])],
        'empty api key' => [fn (User $user) => UserPlugin::factory()->forUser($user)->create(['credentials' => ['api_key' => '']])],
        'unreadable api key' => [function (User $user) {
            $plugin = UserPlugin::factory()->forUser($user)->create();
            $foreign = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');
            DB::table('user_plugins')->where('id', $plugin->id)
                ->update(['credentials' => $foreign->encrypt(json_encode(['api_key' => 'sk-lost']), false)]);
        }],
        'model not in the snapshot' => [fn (User $user) => UserPlugin::factory()->forUser($user)->create([
            'settings' => ['provider' => 'openai', 'model' => 'gpt-retired'],
        ])],
    ]);
});
