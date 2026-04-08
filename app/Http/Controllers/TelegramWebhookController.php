<?php

namespace App\Http\Controllers;

use App\Services\TelegramBotService;
use App\Services\TelegramCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramBotService $bot,
        TelegramCommandService $commandService
    ): JsonResponse {
        $secret = $bot->webhookSecret();
        if ($secret !== '' && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            abort(403);
        }

        $commandService->handleUpdate($request->all());

        return response()->json(['ok' => true]);
    }
}
