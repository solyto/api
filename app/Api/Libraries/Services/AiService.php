<?php

namespace App\Api\Libraries\Services;

use App\Api\Users\Models\User;
use App\Shared\Enums\AiUsageFeatureEnum;
use App\Shared\Models\AiUsage;
use OpenAI;
use OpenAI\Client;

class AiService
{
    private Client $client;
    private string $model;
    private int $inputTokens;
    private int $outputTokens;
    private int $totalTokens;

    public function __construct()
    {
        $factory = OpenAI::factory()
            ->withApiKey(config('services.ai.api_key'));

        $baseUrl = config('services.ai.base_url');
        if ($baseUrl) {
            $factory = $factory->withBaseUri($baseUrl);
        }

        $this->client = $factory->make();
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

        $this->model = $response->model;
        $this->inputTokens = $response->usage->promptTokens;
        $this->outputTokens = $response->usage->completionTokens;
        $this->totalTokens = $response->usage->totalTokens;

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

        $this->model = $response->model;
        $this->inputTokens = $response->usage->promptTokens;
        $this->outputTokens = $response->usage->completionTokens;
        $this->totalTokens = $response->usage->totalTokens;

        return json_decode($response->choices[0]->message->content, true);
    }

    public function saveUsageForUser(User $user, AiUsageFeatureEnum $feature): void
    {
        AiUsage::create([
            'user_id' => $user->id,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'date' => now(),
            'feature' => $feature->value,
        ]);
    }
}
