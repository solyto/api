<?php

namespace App\Plugins\DTOs;

use App\Plugins\Enums\PluginEnum;

class UserPluginStateDTO
{
    public function __construct(
        public readonly PluginEnum $plugin,
        public readonly bool $enabled,
        public readonly bool $hasApiKey,
        public readonly ?array $settings,
        public readonly bool $modelAvailable,
    ) {}
}
