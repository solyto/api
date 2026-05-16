<?php

namespace App\Bots;

use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Shared\Services\IntegrationGateway;
use App\Shared\Services\QuickAddService;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\Users\Models\User;
use App\Bots\DTOs\Keyboard;
use App\Bots\Events\SolytoBotEvent;
use App\Bots\Messages\SolytoMessage;
use App\Bots\Traits\IsTelegramBot;
use Carbon\Carbon;

class SolytoBot implements BotInterface
{
    use IsTelegramBot;

    private const array TYPE_KEYBOARD_MAP = [
        'Todo'      => QuickAddContentType::Todo,
        'Note'      => QuickAddContentType::Note,
        'Quote'     => QuickAddContentType::Quotes,
        'Clipboard' => QuickAddContentType::Clipboard,
        'Link'      => QuickAddContentType::Links,
        'Recipe'    => QuickAddContentType::Recipes,
    ];

    public function __construct(
        private readonly IntegrationGateway $gateway,
    ) {}

    public string $identifier = 'solyto';
    public array $commands = [
        ['/connect', 'Connect your Solyto Account'],
        ['/day', 'Summarize my day'],
        ['/todos', 'List todos'],
        ['/help', 'Show Help'],
    ];
    public array $routes = [
        SolytoBotEvent::QUICK_ADD_AWAITING_TYPE->value => 'handleQuickAddTypeSelection',
    ];
    public string $defaultErrorMessage = SolytoMessage::ERROR->value;

    private ?TelegramBotConnection $connection = null;

    public function entrypoint(): void
    {
        $this->auth();

        $this->state->addUserTextEvent($this->getMessage())
                    ->addActionEvent(SolytoBotEvent::WELCOME->value)
                    ->store();

        $message = trim((string) $this->getMessage());
        if ($message === '') {
            $this->replyWithText(SolytoMessage::WELCOME->value);
            return;
        }

        $this->processQuickAdd($message);
    }

    public function connectCommand(): void
    {
        $token = $this->getMessageWithoutCommand('/connect');
        $connection = TelegramBotConnection::where('token', $token)
                                           ->first();

        if (!$connection) {
            $this->replyWithText(SolytoMessage::INVALID_TOKEN->value);
            return;
        }

        if ($connection->is_confirmed) {
            $this->replyWithText(SolytoMessage::TOKEN_ALREADY_REGISTERED->value);
            return;
        }

        $connection->update([
            'is_confirmed' => true,
            'chat_id' => (string) $this->getChatId(),
        ]);

        $this->replyWithText(SolytoMessage::TOKEN_REGISTERED->value);
    }

    public function dayCommand(): void
    {
        $this->auth();

        $todos = $this->gateway->dueTodos();
        $appointments = $this->gateway->todayAppointments();

        if ($todos->isEmpty() && $appointments->isEmpty()) {
            $this->replyWithText(SolytoMessage::EMPTY_DAY->value);
            return;
        }

        $dayMessage = SolytoMessage::DAY->value;

        foreach ($appointments as $appointment) {
            if ($appointment->is_all_day) {
                $dayMessage .= '- ' . $appointment->title . "\n";
            } else {
                $dayMessage .= '- ' . Carbon::createFromTimestamp($appointment->start_date)->format('H:i') . ' ' . $appointment->title . "\n";
            }
        }

        foreach ($todos as $todo) {
            $dayMessage .= '- ' . $todo->title . "\n";
        }

        $this->replyWithText($dayMessage);
    }

    public function todosCommand(): void
    {
        $this->auth();

        $todos = $this->gateway->todos();

        if ($todos->isEmpty()) {
            $this->replyWithText(SolytoMessage::NO_TODOS->value);
            return;
        }

        $message = '';
        foreach ($todos as $todo) {
            $message .= '- ' . $todo->title . "\n";
        }

        $this->replyWithText(trim($message));
    }

    private function processQuickAdd(string $message): void
    {
        $detection = $this->gateway->detect($message);

        if (!$detection->needsConfirmation) {
            $result = $this->gateway->commit(
                $detection->url,
                $detection->contentType,
                $detection->metadata,
            );

            if ($result === null) {
                $this->replyWithText(SolytoMessage::ADD_FAILED->value);
                return;
            }

            $this->replyWithText($this->messageForType($detection->contentType));
            return;
        }

        $this->state->addActionEvent(SolytoBotEvent::QUICK_ADD_AWAITING_TYPE->value)->store();

        $keyboard = Keyboard::make()
            ->withRow([Keyboard::button('Todo'), Keyboard::button('Note'), Keyboard::button('Quote')])
            ->withRow([Keyboard::button('Clipboard'), Keyboard::button('Link'), Keyboard::button('Recipe')]);

        $this->replyWithTextAndKeyboard(SolytoMessage::CHOOSE_TYPE->value, $keyboard);
    }

    public function handleQuickAddTypeSelection(): void
    {
        $this->auth();

        $selectedLabel = trim((string) $this->getMessage());
        $contentType = self::TYPE_KEYBOARD_MAP[$selectedLabel] ?? null;

        if ($contentType === null) {
            $this->state->addActionEvent(SolytoBotEvent::QUICK_ADD_AWAITING_TYPE->value)->store();
            $keyboard = Keyboard::make()
                ->withRow([Keyboard::button('Todo'), Keyboard::button('Note'), Keyboard::button('Quote')])
                ->withRow([Keyboard::button('Clipboard'), Keyboard::button('Link'), Keyboard::button('Recipe')]);
            $this->replyWithTextAndKeyboard(SolytoMessage::CHOOSE_TYPE->value, $keyboard);
            return;
        }

        $originalMessage = $this->state->getLastUserMessage();
        $content = $originalMessage?->getText() ?? '';

        $this->state->destroy();

        $result = $this->gateway->commit($content, $contentType, null);

        if ($result === null) {
            $this->replyWithText(SolytoMessage::ADD_FAILED->value);
            return;
        }

        $this->replyWithText($this->messageForType($contentType));
    }

    private function commitWithType(string $input, QuickAddContentType $type): void
    {
        $result = $this->gateway->commit($input, $type, null);

        if ($result === null) {
            $this->replyWithText(SolytoMessage::ADD_FAILED->value);
            return;
        }

        $this->replyWithText($this->messageForType($type));
    }

    private function messageForType(QuickAddContentType $type): string
    {
        return match ($type) {
            QuickAddContentType::Links => SolytoMessage::ADDED_LINK->value,
            QuickAddContentType::Music => SolytoMessage::ADDED_MUSIC->value,
            QuickAddContentType::Books => SolytoMessage::ADDED_BOOK->value,
            QuickAddContentType::Movies => SolytoMessage::ADDED_MOVIE->value,
            QuickAddContentType::Games => SolytoMessage::ADDED_GAME->value,
            QuickAddContentType::Recipes => SolytoMessage::ADDED_RECIPE->value,
            QuickAddContentType::Plants => SolytoMessage::ADDED_PLANT->value,
            QuickAddContentType::Quotes => SolytoMessage::ADDED_QUOTE->value,
            QuickAddContentType::Todo => SolytoMessage::ADDED_TODO->value,
            QuickAddContentType::Note => SolytoMessage::ADDED_NOTE->value,
            QuickAddContentType::Feed => SolytoMessage::ADDED_FEED->value,
            QuickAddContentType::Clipboard => SolytoMessage::ADDED_CLIPBOARD->value,
        };
    }

    private function auth(): void
    {
        $this->connection = TelegramBotConnection::forChatId((string) $this->getChatId())->first();

        if (!$this->connection || !$this->connection->is_confirmed) {
            $this->state->addUserTextEvent($this->getMessage())
                        ->addActionEvent(SolytoBotEvent::WELCOME_UNREGISTERED->value)
                        ->store();

            $this->replyWithText(SolytoMessage::WELCOME_UNREGISTERED->value);
            exit(1);
        }

        $user = User::find($this->connection->user_id);
        if ($user === null) {
            $this->replyWithText(SolytoMessage::ERROR->value);
            exit(1);
        }

        $this->gateway->setUser($user);
    }
}
