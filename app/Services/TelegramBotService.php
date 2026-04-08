<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    /**
     * @return array<int, array<string, string>>
     */
    public function commandDefinitions(): array
    {
        return [
            [
                'command' => 'start',
                'description' => 'Connect the bot and show the available commands',
            ],
            [
                'command' => 'help',
                'description' => 'Show help and command usage',
            ],
            [
                'command' => 'follow',
                'description' => 'Follow one or more validator wallet addresses',
            ],
            [
                'command' => 'followall',
                'description' => 'Follow all validators, including new ones',
            ],
            [
                'command' => 'unfollow',
                'description' => 'Stop following validator wallet addresses',
            ],
            [
                'command' => 'unfollowall',
                'description' => 'Stop follow-all validator notifications',
            ],
            [
                'command' => 'list',
                'description' => 'Show followed validator subscriptions',
            ],
            [
                'command' => 'status',
                'description' => 'Show DMD validator and epoch status summary',
            ],
            [
                'command' => 'epochs',
                'description' => 'Turn epoch notifications on or off',
            ],
            [
                'command' => 'epoch',
                'description' => 'Show the current staking epoch',
            ],
        ];
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '';
    }

    public function webhookSecret(): string
    {
        return (string) config('services.telegram.webhook_secret', '');
    }

    public function sendMessage(string $chatId, string $text, ?string $parseMode = null): bool
    {
        return $this->sendMessageResult($chatId, $text, $parseMode)['sent'];
    }

    /**
     * @return array{sent: bool, blocked: bool}
     */
    public function sendMessageResult(string $chatId, string $text, ?string $parseMode = null): array
    {
        if (! $this->isConfigured()) {
            Log::warning('Telegram bot token missing; skipping message.', ['chat_id' => $chatId]);

            return ['sent' => false, 'blocked' => false];
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post($this->apiUrl('sendMessage'), $payload);

        if ($response->failed()) {
            $blocked = $this->isUnavailableChatResponse($response->status(), $response->body());

            Log::error('Telegram sendMessage failed.', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => $response->body(),
                'blocked' => $blocked,
            ]);

            return ['sent' => false, 'blocked' => $blocked];
        }

        return ['sent' => true, 'blocked' => false];
    }

    public function setWebhook(string $url): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $payload = ['url' => $url];
        if ($this->webhookSecret() !== '') {
            $payload['secret_token'] = $this->webhookSecret();
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post($this->apiUrl('setWebhook'), $payload);

        if ($response->failed()) {
            Log::error('Telegram setWebhook failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return (bool) data_get($response->json(), 'ok', false);
    }

    public function setMyCommands(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post($this->apiUrl('setMyCommands'), [
                'commands' => json_encode($this->commandDefinitions(), JSON_THROW_ON_ERROR),
            ]);

        if ($response->failed()) {
            Log::error('Telegram setMyCommands failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return (bool) data_get($response->json(), 'ok', false);
    }

    private function apiUrl(string $method): string
    {
        $baseUrl = rtrim((string) config('services.telegram.base_url', 'https://api.telegram.org'), '/');

        return $baseUrl . '/bot' . $this->token() . '/' . $method;
    }

    private function token(): string
    {
        return (string) config('services.telegram.bot_token', '');
    }

    private function isUnavailableChatResponse(int $status, string $body): bool
    {
        if ($status !== 403) {
            return false;
        }

        $normalizedBody = strtolower($body);

        return str_contains($normalizedBody, 'bot was blocked by the user')
            || str_contains($normalizedBody, 'user is deactivated')
            || str_contains($normalizedBody, 'chat not found');
    }
}
