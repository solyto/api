<?php

namespace App\Bots;

use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Api\Dashboard\Services\QuickAddService;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;
use App\Bots\Events\SolytoBotEvent;
use App\Bots\Messages\SolytoMessage;
use App\Bots\Traits\IsTelegramBot;
use App\Models\NextcloudCalendarEntry;

class SolytoBot
{
    use IsTelegramBot;

    public function __construct(
        private readonly QuickAddService $quickAddService,
    ) {}

    public string $identifier = 'solyto';
    public array $commands = [
        ['/connect', 'Connect your Solyto Account'],
        ['/recommendAlbum', 'Recommend an Album'],
        ['/day', 'Summarize my day'],
        ['/link', 'Add a Link to your Library'],
        ['/todo', 'Add a Todo'],
        ['/help', 'Show Help'],
    ];
    public array $routes = [

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

    public function recommendAlbumCommand(): void
    {
        $this->auth();

        $album = LibraryMusic::forUser($this->connection->user_id)->inRandomOrder()->first();
        $albumMessage = sprintf(SolytoMessage::RECOMMEND_ALBUM->value, $album->title, $album->artist);

        if ($album->link !== null) {
            $albumMessage .= "\n" . $album->link;
        }

        $this->replyWithText($albumMessage);
    }

    public function dayCommand(): void
    {
        $this->auth();

        $todos = Todo::forUser($this->connection->user_id)->where('is_completed', false)->where('due_at', '<=', today())->get();
        $appointments = NextcloudCalendarEntry::forUser($this->connection->user_id)->where('start_date', 'LIKE', date('Y-m-d') . '%')->get();

        if (count($todos) === 0 && count($appointments) === 0) {
            $this->replyWithText(SolytoMessage::EMPTY_DAY->value);
            return;
        }

        $dayMessage = SolytoMessage::DAY->value;

        if (count($appointments) > 0) {
            foreach ($appointments as $appointment) {
                if ($appointment->is_all_day) {
                    $dayMessage .= '- ' . $appointment->title . "\n";
                } else {
                    $dayMessage .= '- ' . $appointment->start_date->format('H:i') . ' ' . $appointment->title . "\n";
                }
            }
        }

        if (count($todos) > 0) {
            foreach ($todos as $todo) {
                $dayMessage .= '- ' . $todo->title . "\n";
            }
        }

        $this->replyWithText($dayMessage);
    }

    private function linkCommand(): void
    {
        $this->auth();

        $input = trim((string) $this->getMessageWithoutCommand('/link'));
        if ($input === '') {
            $this->replyWithText(SolytoMessage::NO_LINK->value);
            return;
        }

        $this->commitWithType($input, QuickAddContentType::Links);
    }

    private function todoCommand(): void
    {
        $this->auth();

        $input = trim((string) $this->getMessageWithoutCommand('/todo'));
        if ($input === '') {
            $this->replyWithText(SolytoMessage::NO_TODO->value);
            return;
        }

        $this->commitWithType($input, QuickAddContentType::Todo);
    }

    private function processQuickAdd(string $message): void
    {
        $user = User::find($this->connection->user_id);
        if ($user === null) {
            $this->replyWithText(SolytoMessage::ERROR->value);
            return;
        }

        $detection = $this->quickAddService->detect($message);
        $result = $this->quickAddService->commit(
            $user,
            $detection->url,
            $detection->contentType,
            $detection->metadata,
        );

        if ($result === null) {
            $this->replyWithText(SolytoMessage::ADD_FAILED->value);
            return;
        }

        $this->replyWithText($this->messageForType($detection->contentType));
    }

    private function commitWithType(string $input, QuickAddContentType $type): void
    {
        $user = User::find($this->connection->user_id);
        if ($user === null) {
            $this->replyWithText(SolytoMessage::ERROR->value);
            return;
        }

        $result = $this->quickAddService->commit($user, $input, $type, null);

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
    }
}
