<?php

namespace App\Plugins\Services;

use App\Api\Users\Models\User;
use App\Plugins\Ai\Enums\AiProviderEnum;
use App\Plugins\Ai\Services\AiModelSnapshot;
use App\Plugins\Enums\PluginEnum;
use App\Plugins\Models\UserPlugin;
use App\Shared\Services\UserCacheService;
use Illuminate\Contracts\Encryption\DecryptException;

class UserPluginService
{
    // Owned by LibraryBookService / LibraryMusicService; their AI recommendations depend on this plugin.
    private const string CACHE_KEY_BOOK_RECOMMENDATIONS = 'book_recommendations';
    private const string CACHE_KEY_MUSIC_RECOMMENDATIONS = 'music_recommendations';

    public function __construct(
        private readonly AiModelSnapshot $snapshot,
        private readonly UserCacheService $cache,
    ) {}

    /**
     * @return array<int, array{plugin: PluginEnum, enabled: bool, has_api_key: bool, settings: ?array, model_available: bool}>
     */
    public function list(User $user): array
    {
        $rows = UserPlugin::forUser($user->id)->get()->keyBy(fn (UserPlugin $row) => $row->plugin->value);

        return array_map(
            fn (PluginEnum $plugin) => $this->state($plugin, $rows->get($plugin->value)),
            PluginEnum::cases()
        );
    }

    /**
     * @return array{plugin: PluginEnum, enabled: bool, has_api_key: bool, settings: ?array, model_available: bool}
     */
    public function find(User $user, PluginEnum $plugin): array
    {
        return $this->state($plugin, $this->row($user, $plugin));
    }

    /**
     * @return array{plugin: PluginEnum, enabled: bool, has_api_key: bool, settings: ?array, model_available: bool}
     */
    public function update(User $user, PluginEnum $plugin, array $data): array
    {
        $row = $this->row($user, $plugin) ?? new UserPlugin(['user_id' => $user->id, 'plugin' => $plugin]);

        if (isset($data['api_key'])) {
            if (! $this->hasReadableCredentials($row)) {
                // The dirty check decrypts the old value; one written under a lost APP_KEY would throw.
                $row->setRawAttributes(array_merge($row->getAttributes(), ['credentials' => null]), true);
            }
            $row->credentials = ['api_key' => $data['api_key']];
        }

        $row->enabled = $data['enabled'];
        $row->settings = match ($plugin) {
            PluginEnum::AI => ['provider' => $data['provider'], 'model' => $data['model']],
        };
        $row->save();

        match ($plugin) {
            PluginEnum::AI => $this->forgetAiRecommendations($user),
        };

        return $this->state($plugin, $row);
    }

    /**
     * @return array<int, array{provider: AiProviderEnum, models: array<int, array{id: string, name: string}>}>
     */
    public function aiProviders(): array
    {
        return array_map(
            fn (AiProviderEnum $provider) => ['provider' => $provider, 'models' => $this->snapshot->models($provider)],
            AiProviderEnum::cases()
        );
    }

    /**
     * The user's usable AI configuration, or null when the plugin is missing, disabled,
     * has no readable key, or points at a model that is not in the snapshot.
     *
     * @return array{provider: AiProviderEnum, api_key: string, model: string}|null
     */
    public function aiConfig(User $user): ?array
    {
        $row = $this->row($user, PluginEnum::AI);

        if ($row === null || ! $row->enabled || ! $this->apiKeyStored($row) || ! $this->aiModelAvailable($row->settings ?? [])) {
            return null;
        }

        return [
            'provider' => AiProviderEnum::from($row->settings['provider']),
            'api_key' => (string) $row->credentials['api_key'],
            'model' => (string) $row->settings['model'],
        ];
    }

    public function hasApiKey(User $user, PluginEnum $plugin): bool
    {
        return $this->apiKeyStored($this->row($user, $plugin));
    }

    private function forgetAiRecommendations(User $user): void
    {
        $this->cache->forget([self::CACHE_KEY_BOOK_RECOMMENDATIONS, $user->id]);
        $this->cache->forget([self::CACHE_KEY_MUSIC_RECOMMENDATIONS, $user->id]);
    }

    private function row(User $user, PluginEnum $plugin): ?UserPlugin
    {
        return UserPlugin::forUser($user->id)->where('plugin', $plugin->value)->first();
    }

    /**
     * @return array{plugin: PluginEnum, enabled: bool, has_api_key: bool, settings: ?array, model_available: bool}
     */
    private function state(PluginEnum $plugin, ?UserPlugin $row): array
    {
        return [
            'plugin' => $plugin,
            'enabled' => $row->enabled ?? false,
            'has_api_key' => $this->apiKeyStored($row),
            'settings' => $row?->settings,
            'model_available' => $this->modelAvailable($plugin, $row),
        ];
    }

    private function apiKeyStored(?UserPlugin $row): bool
    {
        if ($row === null || ! $this->hasReadableCredentials($row)) {
            return false;
        }

        return filled($row->credentials['api_key'] ?? null);
    }

    private function hasReadableCredentials(UserPlugin $row): bool
    {
        try {
            $row->getAttribute('credentials');

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    private function modelAvailable(PluginEnum $plugin, ?UserPlugin $row): bool
    {
        return match ($plugin) {
            PluginEnum::AI => $this->aiModelAvailable($row->settings ?? []),
        };
    }

    private function aiModelAvailable(array $settings): bool
    {
        $provider = AiProviderEnum::tryFrom((string) ($settings['provider'] ?? ''));
        $model = $settings['model'] ?? null;

        return $provider !== null && is_string($model) && $this->snapshot->has($provider, $model);
    }
}
