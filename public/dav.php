<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use App\Dav\Controllers\DavController;
use App\Dav\Factories\DavServerFactory;
use Illuminate\Support\Facades\Log;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$request = Request::capture();

try {
    $factory = $app->make(DavServerFactory::class);
    $controller = new DavController();
    $response = $controller->handle($request, $factory);
} catch (\Throwable $e) {
    Log::channel('dav')->error('DAV Factory or Server error', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
}

// Send raw SabreDAV response
//$response->send();
