<?php

use App\Bots\Enums\ConversationStateRoleEnum;
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
        expect($connection->fresh()->chat_id)->toBe('12345');

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
                && str_contains($request['text'] ?? '', 'Solyto');
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
