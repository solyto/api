<?php

namespace App\Bots;

interface BotInterface
{
    public string $identifier { get; }
    public array $commands { get; }
    public array $routes { get; }
    public string $defaultErrorMessage { get; }

    public function entrypoint(): void;
}
