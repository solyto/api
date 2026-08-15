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

        // Fresh per-process file for the DAV database (see below).
        $davDatabase = storage_path('framework/testing/dav.sqlite');
        if (! static::$davDatabaseInitialized) {
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

        // ConversationState hard-codes the 'conversation_state' cache store.
        $app['config']->set('cache.stores.conversation_state', [
            'driver' => 'array',
            'serialize' => true,
        ]);

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
