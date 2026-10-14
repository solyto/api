<?php

use App\Plugins\Ai\Enums\AiProviderEnum;
use App\Plugins\Ai\Services\AiModelSnapshot;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->snapshot = new AiModelSnapshot(base_path('tests/Fixtures/ai-models.json'));
});

describe('AiModelSnapshot', function () {
    it('lists the models of a provider', function () {
        expect($this->snapshot->models(AiProviderEnum::OPENAI))->toBe([
            ['id' => 'gpt-a', 'name' => 'GPT A'],
            ['id' => 'gpt-b', 'name' => 'GPT B'],
        ]);
    });

    it('knows whether a model is available for a provider', function () {
        expect($this->snapshot->has(AiProviderEnum::OPENROUTER, 'anthropic/claude-sonnet'))->toBeTrue()
            ->and($this->snapshot->has(AiProviderEnum::OPENROUTER, 'unknown/model'))->toBeFalse()
            ->and($this->snapshot->has(AiProviderEnum::MISTRAL, 'gpt-a'))->toBeFalse();
    });

    it('returns nothing for a provider missing from the file', function () {
        $path = sys_get_temp_dir().'/ai-models-'.uniqid().'.json';
        File::put($path, '{"openai": []}');
        $snapshot = new AiModelSnapshot($path);

        expect($snapshot->models(AiProviderEnum::MISTRAL))->toBe([])
            ->and($snapshot->has(AiProviderEnum::MISTRAL, 'mistral-small'))->toBeFalse();

        File::delete($path);
    });
});
