<?php

namespace App\Console\Commands;

use App\Services\TelegramBotService;
use Illuminate\Console\Command;

class SetTelegramWebhookCommand extends Command
{
    protected $signature = 'app:set-telegram-webhook {url}';

    protected $description = 'Register the Telegram bot webhook URL';

    public function __construct(private readonly TelegramBotService $bot)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $url = trim((string) $this->argument('url'));

        if ($url === '') {
            $this->error('Webhook URL is required.');

            return self::FAILURE;
        }

        if (! $this->bot->isConfigured()) {
            $this->error('Missing TELEGRAM_BOT_TOKEN.');

            return self::FAILURE;
        }

        if (! $this->bot->setWebhook($url)) {
            $this->error('Telegram webhook registration failed.');

            return self::FAILURE;
        }

        if (! $this->bot->setMyCommands()) {
            $this->warn('Webhook registered, but Telegram command suggestions could not be updated.');

            return self::SUCCESS;
        }

        $this->info('Telegram webhook registered.');
        $this->info('Telegram slash commands registered.');

        return self::SUCCESS;
    }
}
