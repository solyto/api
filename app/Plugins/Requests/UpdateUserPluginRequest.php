<?php

namespace App\Plugins\Requests;

use App\Plugins\Ai\Enums\AiProviderEnum;
use App\Plugins\Ai\Services\AiModelSnapshot;
use App\Plugins\Enums\PluginEnum;
use App\Plugins\Services\UserPluginService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserPluginRequest extends FormRequest
{
    public function rules(UserPluginService $userPluginService, AiModelSnapshot $snapshot): array
    {
        /** @var PluginEnum $plugin */
        $plugin = $this->route('plugin');
        $provider = is_string($this->input('provider')) ? AiProviderEnum::tryFrom($this->input('provider')) : null;
        $models = $provider ? array_column($snapshot->models($provider), 'id') : [];

        return match ($plugin) {
            PluginEnum::AI => [
                'enabled' => 'required|boolean',
                'api_key' => ($userPluginService->hasApiKey($this->user(), $plugin) ? 'sometimes' : 'required').'|string',
                'provider' => ['required', 'string', Rule::enum(AiProviderEnum::class)],
                'model' => ['required', 'string', Rule::in($models)],
            ],
        };
    }

    public function attributes(): array
    {
        return [
            'api_key' => 'API key',
        ];
    }
}
