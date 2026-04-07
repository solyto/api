<?php

use App\Dav\Controllers\DavController;
use App\Api\Telegram\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/telegram/solyto/{token}', [TelegramWebhookController::class, 'solyto'])
    ->name('telegram.webhook.solyto');

Route::any('dav/{path?}', [DavController::class, 'handle'])->where('path', '.*');
Route::domain('dav.solyto.de')->any('{path?}', [DavController::class, 'handle'])->where('path', '.*');
