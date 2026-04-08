<?php

use App\Console\Commands\SyncDmdChainStatusCommand;
use App\Console\Commands\SyncDmdStateCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(SyncDmdStateCommand::class)
    ->everyFiveMinutes()
    ->appendOutputTo(storage_path('logs/dmd-sync.log'))
    ->runInBackground()
    ->onSuccess(function () {
        Log::info('DMD state sync completed successfully.');
    })
    ->onFailure(function () {
        Log::error('Failed to sync DMD state.');
    })
    ->withoutOverlapping();

Schedule::command(SyncDmdChainStatusCommand::class)
    ->everyMinute()
    ->appendOutputTo(storage_path('logs/dmd-chain-status-sync.log'))
    ->runInBackground()
    ->onSuccess(function () {
        Log::info('DMD chain status sync completed successfully.');
    })
    ->onFailure(function () {
        Log::error('Failed to sync DMD chain status.');
    })
    ->withoutOverlapping();

Schedule::command('queue:work --queue=telegram,default --stop-when-empty --tries=5 --timeout=120')
    ->everyMinute()
    ->appendOutputTo(storage_path('logs/queue-worker.log'))
    ->runInBackground()
    ->onSuccess(function () {
        Log::info('Queue worker completed successfully.');
    })
    ->onFailure(function () {
        Log::error('Queue worker failed.');
    })
    ->withoutOverlapping();
