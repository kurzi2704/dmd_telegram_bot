<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app' => config('app.name'),
        'status' => 'ok',
    ]);
});

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class]);
