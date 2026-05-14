<?php

namespace App\Bots\Traits;

use App\Bots\DTOs\Keyboard;
use App\Shared\Services\TelegramBotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait IsTelegramBot
{
    use HasConversationState;

    private Request $request;
    private TelegramBotService $telegramBotService;
    private bool $debug = false;
    private ?int $debugChatId = null;

    public function handleWebhook(Request $request): void
    {
        try {
            $this->init();

            if (empty($this->request->get('message'))) {
                return;
            }

            $this->initState($this->getChatId(), $this->identifier);

            if ($this->runCommands()) {
                return;
            }

            if ($this->routeMessage()) {
                return;
            }

            $this->entrypoint();
        } catch (\Exception $e) {
            Log::channel('bots')->error($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->replyWithText($this->defaultErrorMessage);
        }
    }

    public function init(): void
    {
        $this->request = request();
        $this->telegramBotService = new TelegramBotService(
            config('telegram.bots.' . $this->identifier . '.telegram_token')
        );

        if (config('app.debug')) {
            $this->debug = true;
            $this->debugChatId = config('telegram.bots.' . $this->identifier . '.debug_chat_id');
        }
    }

    public function getUpdateId(): ?int
    {
        return $this->request->get('update_id');
    }

    public function getChatId(): ?int
    {
        return $this->request->get('message')['chat']['id'];
    }

    public function getFirstName(): ?string
    {
        return $this->request->get('message')['from']['first_name'];
    }

    public function getLastName(): ?string
    {
        return $this->request->get('message')['from']['last_name'];
    }

    public function getUserName(): ?string
    {
        return $this->request->get('message')['from']['username'];
    }

    public function getFromChatId(): ?int
    {
        return $this->request->get('message')['from']['id'];
    }

    public function getMessageId(): ?int
    {
        return $this->request->get('message')['message_id'];
    }

    public function getMessage(): ?string
    {
        return $this->request->get('message')['text'];
    }

    public function getMessageWithoutCommand(string $command): ?string
    {
        return Str::replaceFirst($command . ' ', '', $this->getMessage());
    }

    public function getCommands(): array
    {
        $message = $this->getMessage();
        if (!$message) {
            return [];
        }

        preg_match_all('/\/\w+/', $message, $matches);
        return $matches[0] ?? [];
    }

    public function hasCommand(string $text): bool
    {
        return Str::contains($this->getMessage() ?? '', $text);
    }

    public function runCommands(): bool
    {
        if (empty($this->commands)) {
            return false;
        }

        foreach ($this->commands as $command) {
            if (!$this->hasCommand($command[0])) {
                continue;
            }

            $method = Str::replace('/', '', $command[0]) . 'Command';

            if (!method_exists($this, $method)) {
                continue;
            }

            $this->{$method}();
            return true;
        }

        return false;
    }

    public function routeMessage(): bool
    {
        $action = $this->state->getLastActionEvent();

        if (empty($action)) {
            return false;
        }

        try {
            $this->{$this->routes[$action->getName()]}();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function replyWithText(string $text): bool
    {
        return $this->telegramBotService->sendText($this->debugChatId ?? $this->getChatId(), $text);
    }

    public function replyWithTextAndKeyboard(string $text, Keyboard $keyboard): bool
    {
        return $this->telegramBotService->sendTextWithKeyboard($this->debugChatId ?? $this->getChatId(), $text, ['keyboard' => $keyboard->asArray()]);
    }

    public function sendText(int $chatId, string $text): bool
    {
        return $this->telegramBotService->sendText($this->debugChatId ?? $chatId, $text);
    }
}
