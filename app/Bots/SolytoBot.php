<?php

namespace App\Bots;

use App\Api\Libraries\Models\LibraryLink;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\Todos\Models\Todo;
use App\Bots\Events\SolytoBotEvent;
use App\Bots\Messages\SolytoMessage;
use App\Bots\Traits\IsTelegramBot;
use App\Models\NextcloudCalendarEntry;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SolytoBot
{
    use IsTelegramBot;

    private const string CACHE_KEY_LINKS = 'links';

    public function __construct(private readonly UserCacheService $cache) {}

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

        if ($this->isLink($this->getMessage())) {
            $this->addLinkToLibrary($this->getMessage());
            return;
        }

        $this->replyWithText(SolytoMessage::WELCOME->value);
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

        if ($this->isLink($this->getMessage())) {
            $this->addLinkToLibrary($this->getMessageWithoutCommand('/link'));
        } else {
            $this->replyWithText(SolytoMessage::NO_LINK->value);
        }
    }

    private function todoCommand(): void
    {
        $this->auth();

        $todo = $this->getMessageWithoutCommand('/todo');

        if (empty($todo)) {
            $this->replyWithText(SolytoMessage::NO_TODO->value);
            return;
        }

        Todo::create([
            'user_id' => $this->connection->user_id,
            'title' => $todo
        ]);

        $this->replyWithText(SolytoMessage::TODO->value);
    }

    private function addLinkToLibrary(string $message): void
    {
        preg_match('/https?:\/\/[^\s<>]+/i', $message, $matches);
        $link = $matches[0];
        $title = $link;

        if (Str::length(Str::replace($link, '', $message)) > 0) {
            $title = Str::replace($link, '', $message);
        } else {
            try {
                $response = Http::timeout(15)->get($link);

                if ($response->successful()) {
                    $content = $response->body();

                    if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $content, $matches)) {
                        $title = trim($matches[1]);
                    }
                }
            } catch (\Throwable $e) {
                // Ignore all errors, use link as title
            }
        }

        LibraryLink::create([
            'user_id' => $this->connection->user_id,
            'url' => $link,
            'title' => $title,
        ]);

        $this->cache->forget([self::CACHE_KEY_LINKS, $this->connection->user_id]);
        $this->replyWithText(SolytoMessage::LINK->value);
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

    private function isLink(string $message): bool
    {
        return (bool) preg_match('/https?:\/\/[^\s<>]+/i', $message);
    }
}
