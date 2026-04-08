# DMD Telegram Bot

This repository is an extracted Laravel application focused on DMD validator monitoring and Telegram notifications.

Included functionality:

- DMD pool state sync from the aggregator contract
- DMD chain freshness sync from the RPC endpoint
- Telegram webhook handling and slash-command processing
- Telegram notification queueing for pool and epoch updates

Excluded functionality:

- Vision syncs
- Rewards distribution syncs
- Asset and fiat syncs
- Extra API endpoints unrelated to DMD and Telegram

## Docker Run

1. Copy `.env.example` to `.env`.
2. Configure `TELEGRAM_*` and `DMD_*` variables in `.env`.
3. Start the stack with `docker compose up --build`.

The compose stack starts:

- `app` on `http://localhost:8000`
- `worker` for Telegram jobs
- `scheduler` for Laravel scheduled commands

On startup, the container will install Composer dependencies if needed, create the SQLite database file, generate `APP_KEY`, and run migrations automatically.

## First Telegram Setup

The first Docker startup prepares the Laravel app and database, but it does not register the Telegram webhook automatically.

Before Telegram can work, set these values in `.env`:

- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_WEBHOOK_SECRET`
- `APP_URL` or your public webhook base URL

After the containers are running, register the webhook manually:

```bash
docker compose exec app php artisan app:set-telegram-webhook https://your-domain.example/telegram/webhook
```

You can also run other Laravel commands through Docker, for example:

```bash
docker compose exec app php artisan app:sync-dmd-state
docker compose exec app php artisan app:sync-dmd-chain-status
```

## Local Non-Docker Setup

1. Install PHP dependencies with `composer install`.
2. Copy `.env.example` to `.env`.
3. Generate an app key with `php artisan key:generate`.
4. Create the database file and run `php artisan migrate`.
5. Run the app with `php artisan serve`.

## Useful Commands

- `php artisan app:sync-dmd-state`
- `php artisan app:sync-dmd-chain-status`
- `php artisan app:set-telegram-webhook https://your-domain.example/telegram/webhook`
- `php artisan schedule:run`
