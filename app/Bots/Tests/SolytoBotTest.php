<?php

use App\Bots\Enums\ConversationStateRoleEnum;
use App\Bots\Events\SolytoBotEvent;
use App\Bots\Messages\SolytoMessage;
use App\Bots\SolytoBot;
use App\Bots\State\ConversationState;
use App\Shared\Services\QuickAddService;
use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\Libraries\Models\LibraryLink;
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
        $bot = app(SolytoBot::class);
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
        $bot = app(SolytoBot::class);
        $bot->handleWebhook(request());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && ($request['text'] ?? '') === SolytoMessage::WELCOME_UNREGISTERED->trans();
        });
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
        $bot = app(SolytoBot::class);
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

    it('replies with the default error message when a routed handler throws', function () {
        $user = makeUser();
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '556', 'is_confirmed' => true]);

        $quickAdd = $this->mock(QuickAddService::class);
        $quickAdd->shouldNotReceive('detect');
        $quickAdd->shouldReceive('commit')->once()->andThrow(new \RuntimeException('commit failed'));

        sendToBotWithAction(app(SolytoBot::class), 556, 'buy milk', SolytoBotEvent::QUICK_ADD_AWAITING_TYPE->value, 'Todo');

        $errorText = __(SolytoMessage::ERROR->value);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && ($request['text'] ?? null) === $errorText);
    });

    it('falls through to the entrypoint for an action without a handler', function () {
        $user = makeUser();
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '557', 'is_confirmed' => true]);

        sendToBotWithAction(app(SolytoBot::class), 557, 'hello', SolytoBotEvent::WELCOME->value, 'https://example.com');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && ($request['text'] ?? null) === SolytoMessage::ADDED_LINK->trans());
        expect(LibraryLink::forUser($user->id)->pluck('url')->all())->toBe(['https://example.com']);
    });
});

describe('SolytoBot with real records', function () {
    function sendToBot(int $chatId, string $text): void
    {
        app()->instance('request', \Illuminate\Http\Request::create('/webhook', 'POST', [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $chatId],
                'from' => ['id' => $chatId, 'language_code' => 'en'],
                'text' => $text,
            ],
        ]));

        app(SolytoBot::class)->handleWebhook(request());
    }

    function sentText(): string
    {
        $texts = Http::recorded()
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendMessage'))
            ->map(fn ($pair) => $pair[0]['text'] ?? '');

        expect($texts)->toHaveCount(1);

        return $texts->first();
    }

    afterEach(function () {
        \Carbon\Carbon::setTestNow();
    });

    it('lists the open todos of the connected user only', function () {
        $user = makeUser();
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '600', 'is_confirmed' => true]);
        Todo::factory()->forUser($user)->create(['title' => 'Water plants', 'is_completed' => false]);
        Todo::factory()->forUser(makeUser())->create(['title' => 'Someone else', 'is_completed' => false]);

        sendToBot(600, '/todos');

        expect(sentText())->toBe('- Water plants');
    });

    it('lists todos due today and overdue on /day, without future or completed ones', function () {
        \Carbon\Carbon::setTestNow('2026-08-14 12:00:00');
        $user = makeUser();
        $user->settings()->update(['timezone' => 'UTC']);
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '601', 'is_confirmed' => true]);
        Todo::factory()->forUser($user)->create(['title' => 'Due today', 'is_completed' => false, 'due_at' => '2026-08-14']);
        Todo::factory()->forUser($user)->create(['title' => 'Overdue', 'is_completed' => false, 'due_at' => '2026-08-10']);
        Todo::factory()->forUser($user)->create(['title' => 'Future', 'is_completed' => false, 'due_at' => '2026-08-15']);
        Todo::factory()->forUser($user)->create(['title' => 'Done', 'is_completed' => true, 'due_at' => '2026-08-14']);

        sendToBot(601, '/day');

        $text = sentText();
        expect($text)->toStartWith(SolytoMessage::DAY->trans())
            ->toContain('- Due today')
            ->toContain('- Overdue')
            ->not->toContain('Future')
            ->not->toContain('Done');
        expect(strpos($text, '- Overdue'))->toBeLessThan(strpos($text, '- Due today'));
    });

    it('uses the user\'s date on /day when it is already tomorrow there', function () {
        \Carbon\Carbon::setTestNow('2026-08-14 23:30:00');
        $user = makeUser();
        $user->settings()->update(['timezone' => 'Europe/Berlin']);
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '605', 'is_confirmed' => true]);
        Todo::factory()->forUser($user)->create(['title' => 'Berlin today', 'is_completed' => false, 'due_at' => '2026-08-15']);

        sendToBot(605, '/day');

        expect(sentText())->toContain('- Berlin today');
    });

    it('uses the user\'s date on /day when it is still yesterday there', function () {
        \Carbon\Carbon::setTestNow('2026-08-15 02:00:00');
        $user = makeUser();
        $user->settings()->update(['timezone' => 'America/New_York']);
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '606', 'is_confirmed' => true]);
        Todo::factory()->forUser($user)->create(['title' => 'New York today', 'is_completed' => false, 'due_at' => '2026-08-14']);
        Todo::factory()->forUser($user)->create(['title' => 'New York tomorrow', 'is_completed' => false, 'due_at' => '2026-08-15']);

        sendToBot(606, '/day');

        expect(sentText())->toContain('- New York today')
            ->not->toContain('New York tomorrow');
    });

    it('replies with the empty-day text when nothing is due', function () {
        $user = makeUser();
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '602', 'is_confirmed' => true]);

        sendToBot(602, '/day');

        expect(sentText())->toBe(SolytoMessage::EMPTY_DAY->trans());
    });

    it('adds a link for a high-confidence quick-add', function () {
        $user = makeUser();
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '603', 'is_confirmed' => true]);

        sendToBot(603, 'https://example.com/article');

        expect(sentText())->toBe(SolytoMessage::ADDED_LINK->trans());
        expect(LibraryLink::forUser($user->id)->pluck('url')->all())->toBe(['https://example.com/article']);
    });

    it('adds a todo after the type selection', function () {
        $user = makeUser();
        TelegramBotConnection::factory()->forUser($user)->create(['chat_id' => '604', 'is_confirmed' => true]);
        ConversationState::loadOrMake('604_solyto')
            ->addUserTextEvent('buy milk')
            ->addActionEvent(SolytoBotEvent::QUICK_ADD_AWAITING_TYPE->value)
            ->store();

        sendToBot(604, 'Todo');

        expect(sentText())->toBe(SolytoMessage::ADDED_TODO->trans());
        expect(Todo::forUser($user->id)->pluck('title')->all())->toBe(['buy milk']);
    });
});
