<?php

use App\Api\Clipboard\Controllers\ClipboardController;
use App\Dav\Controllers\DavController;
use App\Api\Telegram\Controllers\TelegramWebhookController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::post('webhooks/telegram/solyto/{token}', [TelegramWebhookController::class, 'solyto'])
    ->name('telegram.webhook.solyto');

// Apps up to 1.1.3 show clipboard images from the public storage URL. Since 1.1.4 the images live on
// the private disk; nginx hands exactly these URLs to the API, which serves them as before: the random
// 32-character name is the key, no login (that's how apps up to 1.1.3 load them). Remove once those apps are gone.
Route::get('storage/user/{user}/clipboard/{file}', [ClipboardController::class, 'legacyImage'])
    ->where(['user' => '[0-9a-f-]{36}', 'file' => '[0-9a-f]{32}\.(jpg|png|gif|webp)'])
    // A plain file response: no session or cookies for every image an old app shows.
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
    ]);

Route::any('dav/{path?}', [DavController::class, 'handle'])->where('path', '.*');
Route::domain('dav.solyto.de')->any('{path?}', [DavController::class, 'handle'])->where('path', '.*');
