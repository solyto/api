<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Whether the DAV sqlite file has been reset for this process.
     * createApplication() runs before every test, but the file-backed DAV
     * database must only be wiped once per PHP process (its tables are
     * created by the DAV migration during the first migrate:fresh).
     */
    protected static bool $davDatabaseInitialized = false;

    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        // Fresh per-process file for the DAV database (see below). The
        // storage/framework/testing directory is not part of a fresh
        // checkout (the .gitkeep files are untracked), so create it here
        // instead of relying on pre-existing working-tree state.
        $davDatabase = storage_path('framework/testing/dav.sqlite');
        if (! static::$davDatabaseInitialized) {
            $davDirectory = dirname($davDatabase);
            if (! is_dir($davDirectory)) {
                mkdir($davDirectory, 0755, true);
            }
            if (file_exists($davDatabase)) {
                unlink($davDatabase);
            }
            touch($davDatabase);
            static::$davDatabaseInitialized = true;
        }

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'sqlite',
            'database' => $davDatabase,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('webpush.database_connection', 'sqlite');
        $app['config']->set('telescope.storage.database.connection', 'sqlite');
        $app['config']->set('pulse.storage.database.connection', 'sqlite');

        // UserCacheService hard-codes the 'user_data' cache store (redis in
        // production); use an in-memory array store in tests.
        $app['config']->set('cache.stores.user_data', [
            'driver' => 'array',
            'serialize' => false,
        ]);

        // The 'longterm' cache store is redis-backed in production; keep it on
        // an in-memory array store in tests as well.
        $app['config']->set('cache.stores.longterm', [
            'driver' => 'array',
            'serialize' => false,
        ]);

        // ConversationState hard-codes the 'conversation_state' cache store.
        $app['config']->set('cache.stores.conversation_state', [
            'driver' => 'array',
            'serialize' => true,
        ]);

        // The app key and the AI service key are read from Docker secrets at
        // runtime (DockerSecretHelper), which do not exist in tests. Use a
        // fixed test key so encryption and the password reset broker work;
        // the AI client is always replaced by a mock in the tests that
        // exercise AiService.
        $app['config']->set('app.key', 'base64:AoYoKps+wlm4si7c1Q8IijIzpTPDxuZds9MS6/cHPlE=');
        $app['config']->set('services.ai.api_key', 'test-key');

        // The image transformation service relies on Imagick/imgproxy which
        // are not available in the test environment; use a no-op double that
        // keeps the base64 helpers functional.
        $app->bind(\App\Shared\Services\Images\ImageTransformationService::class, function () {
            return new class extends \App\Shared\Services\Images\ImageTransformationService
            {
                public function __construct() {}

                public function generatePreview(string $disk, string $path): string|false
                {
                    return $path;
                }

                public function scaleToWidth(string $absolutePath, int $width, int $quality): bool
                {
                    return true;
                }

                public function scaleToFileSize(string $absolutePath, int $maxBytes): bool
                {
                    return true;
                }
            };
        });

        // Wrap the DAV connection in a transaction per test as well, so DAV
        // writes don't leak across tests in the same process.
        $this->connectionsToTransact = ['sqlite', 'pgsql'];

        return $app;
    }
}
