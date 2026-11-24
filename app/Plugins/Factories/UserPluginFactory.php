<?php

namespace App\Plugins\Factories;

use App\Api\Users\Models\User;
use App\Plugins\Ai\Enums\AiProviderEnum;
use App\Plugins\Enums\PluginEnum;
use App\Plugins\Models\UserPlugin;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserPlugin> */
class UserPluginFactory extends Factory
{
    protected $model = UserPlugin::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plugin' => PluginEnum::AI,
            'enabled' => true,
            'credentials' => ['api_key' => 'sk-'.$this->faker->bothify('????????????????')],
            // The first OpenAI model of tests/Fixtures/ai-models.json, the snapshot the plugin tests bind.
            'settings' => ['provider' => AiProviderEnum::OPENAI->value, 'model' => 'gpt-a'],
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
