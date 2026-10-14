<?php

use App\Plugins\Ai\Services\AiModelSnapshot;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->snapshotPath = sys_get_temp_dir().'/ai-models-'.uniqid().'.json';
    $this->app->instance(AiModelSnapshot::class, new AiModelSnapshot($this->snapshotPath));
});

afterEach(function () {
    File::delete($this->snapshotPath);
});

describe('ai:refresh-models', function () {
    it('writes only enum providers with structured-output, non-deprecated models in sorted order', function () {
        Http::fake([
            'models.dev/api.json' => Http::response(File::get(base_path('tests/Fixtures/models-dev.json'))),
        ]);

        $this->artisan('ai:refresh-models')->assertSuccessful();

        expect(File::get($this->snapshotPath))->toBe(json_encode([
            'mistral' => [
                ['id' => 'mistral-small', 'name' => 'Mistral Small'],
            ],
            'openai' => [
                ['id' => 'gpt-a', 'name' => 'GPT A'],
                ['id' => 'gpt-b', 'name' => 'GPT B'],
            ],
            'openrouter' => [
                ['id' => 'a-vendor/alpha', 'name' => 'Alpha'],
                ['id' => 'z-vendor/zeta', 'name' => 'Zeta'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    });

    it('does not touch the committed snapshot', function () {
        $committed = resource_path('data/ai-models.json');
        $before = md5_file($committed);
        Http::fake([
            'models.dev/api.json' => Http::response(File::get(base_path('tests/Fixtures/models-dev.json'))),
        ]);

        $this->artisan('ai:refresh-models')->assertSuccessful();

        expect(md5_file($committed))->toBe($before);
    });

    it('fails and keeps the existing file when models.dev errors', function () {
        File::put($this->snapshotPath, "{}\n");
        Http::fake(['models.dev/api.json' => Http::response('', 500)]);

        $this->artisan('ai:refresh-models')->assertFailed();

        expect(File::get($this->snapshotPath))->toBe("{}\n");
    });

    it('fails and keeps the existing file when a provider is missing', function () {
        File::put($this->snapshotPath, "{}\n");
        $catalog = File::json(base_path('tests/Fixtures/models-dev.json'));
        unset($catalog['mistral']);
        Http::fake(['models.dev/api.json' => Http::response($catalog)]);

        $this->artisan('ai:refresh-models')->assertFailed();

        expect(File::get($this->snapshotPath))->toBe("{}\n");
    });
});
