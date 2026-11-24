<?php

namespace App\Api\Integrations\Services;

use App\Shared\Enums\IntegrationEnum;

class IntegrationService
{
    /** @return array<string, bool> */
    public function configured(): array
    {
        return collect(IntegrationEnum::cases())
            ->mapWithKeys(fn (IntegrationEnum $integration) => [$integration->value => $integration->isConfigured()])
            ->all();
    }
}
