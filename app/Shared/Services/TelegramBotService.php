<?php

namespace App\Shared\Services;

use Illuminate\Support\Facades\Http;

class TelegramBotService
{
    private string $baseUrl;

    public function __construct(private readonly string $accessToken)
    {
        $this->baseUrl = sprintf(config('services.telegram.api.base_url'), $accessToken);
    }

    public function sendText(string $chatId, string $content): bool
    {
        $response = Http::post($this->baseUrl . 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $content,
        ]);

        return $response->successful();
    }

    public function sendTextWithKeyboard(string $chatId, string $content, array $keyboard): bool
    {
        $response = Http::post($this->baseUrl . 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $content,
            'reply_markup' => json_encode($keyboard)
        ]);

        return $response->successful();
    }

    public function setWebhook(array $bot): bool
    {
        $response = Http::post($this->baseUrl . 'setWebhook', [
            'url' => route('telegram.webhook.' . $bot['name'], $bot['webhook_token'])
        ]);

        return $response->successful();
    }

    public function deleteWebhook(): bool
    {
        $response = Http::post($this->baseUrl . 'deleteWebhook');

        return $response->successful();
    }

    public function getStatus(): ?array
    {
        $response = Http::get($this->baseUrl . 'getWebhookInfo');

        if (!$response->successful()) {
            return null;
        }

        return $response->json();
    }

    public function setCommands(array $bot): bool
    {
        $class = app($bot['class']);
        $commands = $class->commands;
        $payload = [];

        foreach ($commands as $command) {
            $payload[] = [
                'command' => $command[0],
                'description' => $command[1]
            ];
        }

        $response = Http::post($this->baseUrl . 'setMyCommands', [
            'commands' => $payload
        ]);

        return $response->successful();
    }
}
