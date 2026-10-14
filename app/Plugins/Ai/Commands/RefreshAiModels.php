<?php

namespace App\Plugins\Ai\Commands;

use App\Plugins\Ai\Enums\AiProviderEnum;
use App\Plugins\Ai\Services\AiModelSnapshot;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class RefreshAiModels extends Command
{
    private const string SOURCE_URL = 'https://models.dev/api.json';

    protected $signature = 'ai:refresh-models';

    protected $description = 'Refresh the committed AI model snapshot from models.dev (run by a developer, not scheduled)';

    public function handle(AiModelSnapshot $snapshot): int
    {
        try {
            $response = Http::timeout(60)->get(self::SOURCE_URL);
        } catch (ConnectionException $e) {
            $this->error('Could not reach models.dev: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error('models.dev returned HTTP '.$response->status().'.');

            return self::FAILURE;
        }

        $catalog = $response->json();
        $result = [];

        foreach (AiProviderEnum::cases() as $provider) {
            $models = $catalog[$provider->value]['models'] ?? null;

            // An empty list would silently delete every model of this provider in the next committed diff.
            if (!is_array($models)) {
                $this->error("Provider {$provider->value} is missing from models.dev.");

                return self::FAILURE;
            }

            $result[$provider->value] = $this->filter($models);
        }

        ksort($result, SORT_STRING);

        File::ensureDirectoryExists(dirname($snapshot->path()));
        File::put(
            $snapshot->path(),
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
        );

        foreach ($result as $provider => $models) {
            $this->line("{$provider}: ".count($models).' models');
        }
        $this->info('Wrote '.$snapshot->path());

        return self::SUCCESS;
    }

    /**
     * @param array<string, array<string, mixed>> $models
     * @return array<int, array{id: string, name: string}>
     */
    private function filter(array $models): array
    {
        $kept = [];

        foreach ($models as $model) {
            if (($model['structured_output'] ?? false) !== true || ($model['status'] ?? null) === 'deprecated') {
                continue;
            }

            $kept[] = ['id' => (string) $model['id'], 'name' => (string) $model['name']];
        }

        usort($kept, fn (array $a, array $b) => strcmp($a['id'], $b['id']));

        return $kept;
    }
}
