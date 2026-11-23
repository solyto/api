<?php

namespace App\Bots;

interface BotInterface
{
    public string $identifier { get; }
    public array $commands { get; }
    public string $defaultErrorMessage { get; }

    public function entrypoint(): void;

    // Runs the handler for the conversation's last action event; false when none is registered,
    // so the bot falls back to entrypoint().
    public function routeAction(string $name): bool;
}
