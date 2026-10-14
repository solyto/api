<?php

namespace App\Api\Libraries\Services;

use OpenAI;
use OpenAI\Contracts\ClientContract;

class AiService
{
    private ClientContract $client;
    private string $model;

    public function __construct(?ClientContract $client = null)
    {
        $factory = OpenAI::factory()
            ->withApiKey((string) config('services.ai.api_key'));

        $baseUrl = config('services.ai.base_url');
        if ($baseUrl) {
            $factory = $factory->withBaseUri($baseUrl);
        }

        $this->client = $client ?? $factory->make();
        $this->model = config('services.ai.model');
    }

    public function respond(
        string $prompt,
        array | string $input
    ): string
    {
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

        $response = $this->client->chat()->create([
            'model' => $this->model,
            'messages' => $messages,
        ]);

        return $response->choices[0]->message->content;
    }

    public function respondStructured(
        string $prompt,
        string $input,
        array $responseFormat
    ): array
    {
        $response = $this->client->chat()->create([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $input],
            ],
            'response_format' => $responseFormat
        ]);

        return json_decode($response->choices[0]->message->content, true);
    }
}
