<?php

namespace App\Jobs;

use App\Models\TelegramChat;
use App\Models\TelegramNotificationLog;
use App\Services\TelegramBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTelegramNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $telegramChatId,
        public readonly string $eventKey,
        public readonly string $category,
        public readonly string $message,
        public readonly array $payload = [],
    ) {
        $this->onQueue('telegram');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(TelegramBotService $bot): void
    {
        $chat = TelegramChat::query()->find($this->telegramChatId);
        if (! $chat instanceof TelegramChat || ! $chat->is_active) {
            return;
        }

        $alreadySent = TelegramNotificationLog::query()
            ->where('telegram_chat_id', $chat->id)
            ->where('event_key', $this->eventKey)
            ->exists();

        if ($alreadySent) {
            return;
        }

        $result = $bot->sendMessageResult($chat->chat_id, $this->message);

        if ($result['blocked']) {
            $chat->forceFill(['is_active' => false])->save();

            return;
        }

        if (! $result['sent']) {
            $this->release(30);

            return;
        }

        TelegramNotificationLog::create([
            'telegram_chat_id' => $chat->id,
            'event_key' => $this->eventKey,
            'category' => $this->category,
            'staking_address' => $this->payload['staking_address'] ?? null,
            'sent_at' => now(),
        ]);
    }
}
