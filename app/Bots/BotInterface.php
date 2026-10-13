<?php

namespace App\Bots;

interface BotInterface
{
    public string $identifier { get; }
    public array $commands { get; }
    public string $defaultErrorMessage { get; }

    public function entrypoint(): void;

    /**
     * Run the handler for the conversation's last action event. Returns false
     * when no handler is registered for it, so the bot falls back to entrypoint().
     */
    public function routeAction(string $name): bool;
}
