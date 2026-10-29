<?php

use App\Bots\Enums\ConversationStateRoleEnum;
use App\Bots\Events\SolytoBotEvent;
use App\Bots\Messages\SolytoMessage;
use App\Bots\SolytoBot;
use App\Bots\State\ConversationState;
use App\Shared\Services\IntegrationGateway;
use App\Api\Dashboard\DTOs\DetectionResult;
use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\Todos\Models\Todo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('telegram.bots.solyto.telegram_token', 'test-token');
    config()->set('telegram.bots.solyto.debug_chat_id', null);
    Http::fake();
});

describe('ConversationState', function () {
    it('stores user text events and retrieves the last user message', function () {
        $state = ConversationState::loadOrMake('test-chat');

        $state->addUserTextEvent('first message');
        $state->addUserTextEvent('second message');

        expect($state->getLastUserMessage()->getText())->toBe('second message');
        expect($state->getLastUserMessage()->getRole())->toBe(ConversationStateRoleEnum::USER);
    });

    it('stores action events and retrieves the last action', function () {
        $state = ConversationState::loadOrMake('test-chat');

        $state->addActionEvent('some.action');
        $state->addUserTextEvent('hello');

        expect($state->getLastActionEvent()->getName())->toBe('some.action');
    });

    it('persists and destroys via the cache', function () {
        $state = ConversationState::loadOrMake('persisted-chat');
        $state->addUserTextEvent('hello');
        $state->store();

        $reloaded = ConversationState::loadOrMake('persisted-chat');
        expect($reloaded->getLastUserMessage()->getText())->toBe('hello');

        $reloaded->destroy();
        $gone = ConversationState::loadOrMake('persisted-chat');
        expect($gone->getLastUserMessage())->toBeNull();
    });
});

describe('SolytoBot connect flow', function () {
    function makeBot(array $requestData): SolytoBot
    {
        app()->instance('request', \Illuminate\Http\Request::create('/webhook', 'POST', $requestData));
        $bot = new SolytoBot(Mockery::mock(IntegrationGateway::class));
        $bot->handleWebhook(request());
        return $bot;
    }

    it('registers a chat with a valid token', function () {
        $user = makeUser();
        $connection = TelegramBotConnection::factory()->forUser($user)->create(['token' => 'valid-token', 'is_confirmed' => false]);

        makeBot([
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 12345],
                'from' => ['id' => 12345, 'language_code' => 'en'],
                'text' => '/connect valid-token',
            ],
        ]);

        expect($connection->fresh()->is_confirmed)->toBeTrue();
        expect($connection->fresh()->chat_id)->toBe(12345);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    });

    it('rejects an invalid token', function () {
        makeBot([
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 12345],
                'from' => ['id' => 12345, 'language_code' => 'en'],
                'text' => '/connect wrong-token',
            ],
        ]);

        expect(TelegramBotConnection::where('is_confirmed', true)->count())->toBe(0);
    });
});

describe('SolytoBot quick-add flow', function () {
    it('commits high-confidence detections automatically', function () {
        $user = makeUser();
        $connection = TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '999', 'is_confirmed' => true]);

        $gateway = $this->mock(IntegrationGateway::class);
        $gateway->shouldReceive('setUser')->with(Mockery::on(fn ($u) => $u->id === $user->id));
        $gateway->shouldReceive('detect')->once()->andReturn(
            new DetectionResult('https://example.com', QuickAddContentType::Links, 0.95, false, null)
        );
        $gateway->shouldReceive('commit')->once()->with('https://example.com', QuickAddContentType::Links, null)->andReturn((object) ['id' => 1]);

        app()->instance('request', \Illuminate\Http\Request::create('/webhook', 'POST', [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 999],
                'from' => ['id' => 999, 'language_code' => 'en'],
                'text' => 'https://example.com',
            ],
        ]));
        $bot = new SolytoBot($gateway);
        $bot->handleWebhook(request());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    });

    it('sends a welcome message for unregistered chats', function () {
        app()->instance('request', \Illuminate\Http\Request::create('/webhook', 'POST', [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 777],
                'from' => ['id' => 777, 'language_code' => 'en'],
                'text' => 'hi',
            ],
        ]));
        $bot = new SolytoBot(Mockery::mock(IntegrationGateway::class));
        $bot->handleWebhook(request());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && ($request['text'] ?? '') === SolytoMessage::WELCOME_UNREGISTERED->trans();
        });
    });
});

describe('SolytoBot day and todos commands', function () {
    it('lists due todos for the day', function () {
        $user = makeUser();
        $connection = TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '888', 'is_confirmed' => true]);
        Todo::factory()->forUser($user)->create(['title' => 'Write report']);

        $gateway = $this->mock(IntegrationGateway::class);
        $gateway->shouldReceive('setUser')->once();
        $gateway->shouldReceive('dueTodos')->once()->andReturn(collect([(object) ['title' => 'Write report']]));
        $gateway->shouldReceive('todayAppointments')->once()->andReturn(collect());

        app()->instance('request', \Illuminate\Http\Request::create('/webhook', 'POST', [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 888],
                'from' => ['id' => 888, 'language_code' => 'en'],
                'text' => '/day',
            ],
        ]));
        $bot = new SolytoBot($gateway);
        $bot->handleWebhook(request());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'] ?? '', 'Write report');
        });
    });

    it('sends an empty-day message when nothing is due', function () {
        $user = makeUser();
        $connection = TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '887', 'is_confirmed' => true]);

        $gateway = $this->mock(IntegrationGateway::class);
        $gateway->shouldReceive('setUser')->once();
        $gateway->shouldReceive('dueTodos')->once()->andReturn(collect());
        $gateway->shouldReceive('todayAppointments')->once()->andReturn(collect());

        app()->instance('request', \Illuminate\Http\Request::create('/webhook', 'POST', [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 887],
                'from' => ['id' => 887, 'language_code' => 'en'],
                'text' => '/day',
            ],
        ]));
        $bot = new SolytoBot($gateway);
        $bot->handleWebhook(request());

        expect(SolytoMessage::EMPTY_DAY->trans())->toBeString();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    });
});

describe('SolytoBot day command with calendar events', function () {
    afterEach(function () {
        \Carbon\Carbon::setTestNow();
    });

    it('lists today\'s calendar events in the user timezone', function () {
        \Carbon\Carbon::setTestNow('2026-08-14 05:00:00');
        $timezone = 'Europe/Berlin';

        $user = makeUser();
        $user->settings()->update(['timezone' => $timezone]);
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '886', 'is_confirmed' => true]);

        $instanceId = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data')[0]['id'];

        $storeEvent = function (array $payload) use ($user, $instanceId) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/calendars/'.$instanceId.'/events', array_merge($payload, [
                    'calendar_id' => $instanceId,
                ]))
                ->assertStatus(201);
        };

        $today = today($timezone)->toDateString();
        $yesterday = today($timezone)->subDay()->toDateString();
        $tomorrow = today($timezone)->addDay()->toDateString();

        $storeEvent(['title' => 'Dentist', 'start_date' => $today.' 09:30', 'end_date' => $today.' 10:30', 'is_all_day' => false]);
        $storeEvent(['title' => 'Holiday', 'start_date' => $today, 'end_date' => $today, 'is_all_day' => true]);
        $storeEvent(['title' => 'Standup', 'start_date' => $yesterday.' 08:00', 'end_date' => $yesterday.' 08:15', 'is_all_day' => false, 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY']);
        $storeEvent(['title' => 'Tomorrow Meeting', 'start_date' => $tomorrow.' 09:00', 'end_date' => $tomorrow.' 10:00', 'is_all_day' => false]);

        app()->instance('request', \Illuminate\Http\Request::create('/webhook', 'POST', [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 886],
                'from' => ['id' => 886, 'language_code' => 'en'],
                'text' => '/day',
            ],
        ]));
        $bot = new SolytoBot(app(IntegrationGateway::class));
        $bot->handleWebhook(request());

        Http::assertSent(function ($request) {
            $text = $request['text'] ?? '';

            return str_contains($request->url(), 'sendMessage')
                && str_contains($text, '- Holiday')
                && str_contains($text, '- 09:30 Dentist')
                && str_contains($text, '- 08:00 Standup')
                && !str_contains($text, 'Tomorrow Meeting');
        });
    });
});

describe('SolytoBot action routing', function () {
    function sendToBotWithAction(SolytoBot $bot, int $chatId, string $previousText, string $action, string $text): void
    {
        ConversationState::loadOrMake($chatId . '_solyto')
            ->addUserTextEvent($previousText)
            ->addActionEvent($action)
            ->store();

        app()->instance('request', \Illuminate\Http\Request::create('/webhook', 'POST', [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $chatId],
                'from' => ['id' => $chatId, 'language_code' => 'en'],
                'text' => $text,
            ],
        ]));

        $bot->handleWebhook(request());
    }

    it('routes the quick-add type selection to its handler', function () {
        $user = makeUser();
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '555', 'is_confirmed' => true]);

        $gateway = $this->mock(IntegrationGateway::class);
        $gateway->shouldReceive('setUser');
        $gateway->shouldNotReceive('detect');
        $gateway->shouldReceive('commit')->once()->with('buy milk', QuickAddContentType::Todo, null)->andReturn((object) ['id' => 1]);

        sendToBotWithAction(new SolytoBot($gateway), 555, 'buy milk', SolytoBotEvent::QUICK_ADD_AWAITING_TYPE->value, 'Todo');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    });

    it('replies with the default error message when a routed handler throws', function () {
        $user = makeUser();
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '556', 'is_confirmed' => true]);

        $gateway = $this->mock(IntegrationGateway::class);
        $gateway->shouldReceive('setUser');
        $gateway->shouldNotReceive('detect');
        $gateway->shouldReceive('commit')->once()->andThrow(new \RuntimeException('commit failed'));

        sendToBotWithAction(new SolytoBot($gateway), 556, 'buy milk', SolytoBotEvent::QUICK_ADD_AWAITING_TYPE->value, 'Todo');

        $errorText = __(SolytoMessage::ERROR->value);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && ($request['text'] ?? null) === $errorText);
    });

    it('falls through to the entrypoint for an action without a handler', function () {
        $user = makeUser();
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '557', 'is_confirmed' => true]);

        $gateway = $this->mock(IntegrationGateway::class);
        $gateway->shouldReceive('setUser');
        $gateway->shouldReceive('detect')->once()->andReturn(
            new DetectionResult('https://example.com', QuickAddContentType::Links, 0.95, false, null)
        );
        $gateway->shouldReceive('commit')->once()->with('https://example.com', QuickAddContentType::Links, null)->andReturn((object) ['id' => 1]);

        sendToBotWithAction(new SolytoBot($gateway), 557, 'hello', SolytoBotEvent::WELCOME->value, 'https://example.com');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    });
});
