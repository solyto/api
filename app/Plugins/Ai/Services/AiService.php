<?php

namespace App\Plugins\Ai\Services;

use App\Api\Users\Models\User;
use App\Plugins\Services\UserPluginService;
use OpenAI;
use OpenAI\Contracts\ClientContract;
use Psr\Http\Client\ClientInterface;

class AiService
{
    public function __construct(
        private readonly UserPluginService $userPluginService,
        private readonly ?ClientContract $client = null,
        private readonly ?ClientInterface $httpClient = null,
    ) {}

    /**
     * Returns null without making a request when the user's AI plugin is not usable.
     */
    public function respond(
        User $user,
        string $prompt,
        array | string $input
    ): ?string
    {
        $config = $this->userPluginService->aiConfig($user);

        if ($config === null) {
            return null;
        }

        $messages = [
            ['role' => 'system', 'content' => $prompt],
        ];

        if (is_array($input)) {
            foreach ($input as $message) {
                $messages[] = $message;
            }
        } else {
            $messages[] = ['role' => 'user', 'content' => $input];
        }

        $response = $this->client($config)->chat()->create([
            'model' => $config['model'],
            'messages' => $messages,
        ]);

        return $response->choices[0]->message->content;
    }

    /**
     * Returns null without making a request when the user's AI plugin is not usable.
     */
    public function respondStructured(
        User $user,
        string $prompt,
        string $input,
        array $responseFormat
    ): ?array
    {
        $config = $this->userPluginService->aiConfig($user);

        if ($config === null) {
            return null;
        }

        $response = $this->client($config)->chat()->create([
            'model' => $config['model'],
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $input],
            ],
            'response_format' => $responseFormat
        ]);

        return json_decode($response->choices[0]->message->content, true);
    }

    /**
     * @param  array{provider: \App\Plugins\Ai\Enums\AiProviderEnum, api_key: string, model: string}  $config
     */
    private function client(array $config): ClientContract
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $factory = OpenAI::factory()
            ->withApiKey($config['api_key'])
            ->withBaseUri($config['provider']->baseUrl());

        if ($this->httpClient !== null) {
            $factory = $factory->withHttpClient($this->httpClient);
        }

        return $factory->make();
    }
}
