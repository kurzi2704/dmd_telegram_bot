<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramNotificationJob;
use App\Models\TelegramChat;
use App\Models\TelegramPoolSubscription;
use App\Services\DmdTelegramNotifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DmdTelegramNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();

        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.base_url', 'https://api.telegram.org');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('telegram_notification_logs');
        Schema::dropIfExists('telegram_pool_subscriptions');
        Schema::dropIfExists('telegram_chats');

        parent::tearDown();
    }

    public function test_pool_changes_queue_only_matching_subscriptions(): void
    {
        $chat = TelegramChat::create([
            'chat_id' => '999',
            'is_active' => true,
        ]);

        TelegramPoolSubscription::create([
            'telegram_chat_id' => $chat->id,
            'staking_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ]);

        app(DmdTelegramNotifier::class)->notifyPoolChanges([
            [
                'staking_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'fields' => [
                    'frontend_status' => ['from' => 'Invalid', 'to' => 'Active'],
                    'score' => ['from' => '10', 'to' => '11'],
                ],
            ],
        ], 'batch-1');

        Queue::assertPushed(SendTelegramNotificationJob::class, function ($job) {
            return $job->category === 'pool'
                && $job->payload['staking_address'] === '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
                && str_contains($job->message, 'Status: Invalid -> Active')
                && str_contains($job->message, 'Score: 10 -> 11');
        });
    }

    public function test_pool_change_formats_total_stake_with_18_decimals(): void
    {
        $chat = TelegramChat::create([
            'chat_id' => '1003',
            'is_active' => true,
        ]);

        TelegramPoolSubscription::create([
            'telegram_chat_id' => $chat->id,
            'staking_address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ]);

        app(DmdTelegramNotifier::class)->notifyPoolChanges([
            [
                'staking_address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'fields' => [
                    'total_stake' => [
                        'from' => '1230000000000000000',
                        'to' => '25000000000000000000',
                    ],
                ],
            ],
        ], 'batch-1b');

        Queue::assertPushed(SendTelegramNotificationJob::class, function ($job) {
            return $job->category === 'pool'
                && $job->payload['staking_address'] === '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
                && str_contains($job->message, 'Total stake: 1.2300 DMD -> 25.0000 DMD');
        });
    }

    public function test_follow_all_chats_receive_a_batched_pool_digest(): void
    {
        $chat = TelegramChat::create([
            'chat_id' => '1004',
            'is_active' => true,
            'wants_all_pool_notifications' => true,
        ]);

        TelegramPoolSubscription::create([
            'telegram_chat_id' => $chat->id,
            'staking_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ]);

        app(DmdTelegramNotifier::class)->notifyPoolChanges([
            [
                'staking_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'fields' => [
                    'frontend_status' => ['from' => 'Invalid', 'to' => 'Active'],
                ],
            ],
            [
                'staking_address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'fields' => [
                    'score' => ['from' => '10', 'to' => '11'],
                ],
            ],
        ], 'batch-all-1');

        Queue::assertPushed(SendTelegramNotificationJob::class, function ($job) {
            return $job->category === 'pool'
                && $job->eventKey === 'pool-all:batch-all-1:0'
                && str_contains($job->message, 'DMD validator update')
                && str_contains($job->message, '2 validators changed')
                && str_contains($job->message, '* individually followed')
                && str_contains($job->message, 'Highlights')
                && str_contains($job->message, '- 1 other status changes')
                && str_contains($job->message, '- 1 score changes')
                && str_contains($job->message, 'Other status changes')
                && str_contains($job->message, '* 0xaaaa...aaaaaa: Invalid -> Active')
                && str_contains($job->message, 'Score changes')
                && str_contains($job->message, '  0xbbbb...bbbbbb: 10 -> 11');
        });
    }

    public function test_follow_all_skips_individual_pool_messages_for_same_chat(): void
    {
        $chat = TelegramChat::create([
            'chat_id' => '1005',
            'is_active' => true,
            'wants_all_pool_notifications' => true,
        ]);

        TelegramPoolSubscription::create([
            'telegram_chat_id' => $chat->id,
            'staking_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ]);

        app(DmdTelegramNotifier::class)->notifyPoolChanges([
            [
                'staking_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'fields' => [
                    'frontend_status' => ['from' => 'Invalid', 'to' => 'Active'],
                ],
            ],
        ], 'batch-all-2');

        Queue::assertPushed(SendTelegramNotificationJob::class, 1);
        Queue::assertPushed(SendTelegramNotificationJob::class, function ($job) {
            return $job->eventKey === 'pool-all:batch-all-2:0';
        });
    }

    public function test_follow_all_batches_emphasize_invalid_status_changes(): void
    {
        TelegramChat::create([
            'chat_id' => '1006',
            'is_active' => true,
            'wants_all_pool_notifications' => true,
        ]);

        app(DmdTelegramNotifier::class)->notifyPoolChanges([
            [
                'staking_address' => '0xcccccccccccccccccccccccccccccccccccccccc',
                'fields' => [
                    'frontend_status' => ['from' => 'Active', 'to' => 'Invalid'],
                ],
            ],
        ], 'batch-all-3');

        Queue::assertPushed(SendTelegramNotificationJob::class, function ($job) {
            return $job->category === 'pool'
                && $job->eventKey === 'pool-all:batch-all-3:0'
                && str_contains($job->message, '- 1 moved to Invalid')
                && str_contains($job->message, 'Status: -> Invalid')
                && str_contains($job->message, '0xcccc...cccccc');
        });
    }

    public function test_follow_all_batches_repeat_status_related_score_and_stake_in_detail_sections(): void
    {
        TelegramChat::create([
            'chat_id' => '1007',
            'is_active' => true,
            'wants_all_pool_notifications' => true,
        ]);

        app(DmdTelegramNotifier::class)->notifyPoolChanges([
            [
                'staking_address' => '0xdddddddddddddddddddddddddddddddddddddddd',
                'fields' => [
                    'frontend_status' => ['from' => 'Active', 'to' => 'Valid'],
                    'score' => ['from' => '100', 'to' => '120'],
                    'total_stake' => [
                        'from' => '1000000000000000000',
                        'to' => '2500000000000000000',
                    ],
                ],
            ],
        ], 'batch-all-4');

        Queue::assertPushed(SendTelegramNotificationJob::class, function ($job) {
            return $job->category === 'pool'
                && $job->eventKey === 'pool-all:batch-all-4:0'
                && str_contains($job->message, 'Status: Active -> Valid')
                && str_contains($job->message, '  0xdddd...dddddd')
                && str_contains($job->message, 'Score changes')
                && str_contains($job->message, '  0xdddd...dddddd: 100 -> 120')
                && str_contains($job->message, 'Stake changes')
                && str_contains($job->message, '  0xdddd...dddddd: 1.0000 DMD -> 2.5000 DMD');
        });
    }

    public function test_new_epoch_queues_notifications_for_opted_in_chats(): void
    {
        TelegramChat::create([
            'chat_id' => '1000',
            'is_active' => true,
            'wants_epoch_notifications' => true,
        ]);

        app(DmdTelegramNotifier::class)->notifyEpochChange([
            'type' => 'started',
            'previous' => [
                'staking_epoch' => 12,
                'keygen_round' => 1,
                'staking_epoch_start_time' => 1700000000,
                'staking_epoch_start_block' => 123,
                'are_stake_and_withdraw_allowed' => true,
                'staking_fixed_epoch_end_time' => 1700003600,
                'staking_fixed_epoch_duration' => 3600,
                'staking_withdraw_disallow_period' => 300,
                'delta_pot' => '10',
                'reinsert_pot' => '20',
            ],
            'current' => [
                'staking_epoch' => 13,
                'keygen_round' => 2,
                'staking_epoch_start_time' => 1700007200,
                'staking_epoch_start_block' => 456,
                'are_stake_and_withdraw_allowed' => false,
                'staking_fixed_epoch_end_time' => 1700010800,
                'staking_fixed_epoch_duration' => 3600,
                'staking_withdraw_disallow_period' => 300,
                'delta_pot' => '10',
                'reinsert_pot' => '20',
            ],
            'fields' => [],
        ], 'batch-2');

        Queue::assertPushed(SendTelegramNotificationJob::class, function ($job) {
            return $job->category === 'epoch'
                && str_contains($job->message, 'DMD new epoch started')
                && str_contains($job->message, 'Current epoch: 13');
        });
    }

    public function test_epoch_without_previous_record_still_queues_notifications(): void
    {
        TelegramChat::create([
            'chat_id' => '1001',
            'is_active' => true,
            'wants_epoch_notifications' => true,
        ]);

        app(DmdTelegramNotifier::class)->notifyEpochChange([
            'type' => 'started',
            'previous' => null,
            'current' => [
                'staking_epoch' => 14,
                'keygen_round' => 3,
                'staking_epoch_start_time' => 1700014400,
                'staking_epoch_start_block' => 789,
                'are_stake_and_withdraw_allowed' => true,
                'staking_fixed_epoch_end_time' => 1700018000,
                'staking_fixed_epoch_duration' => 3600,
                'staking_withdraw_disallow_period' => 300,
                'delta_pot' => '10',
                'reinsert_pot' => '20',
            ],
            'fields' => [],
        ], 'batch-3');

        Queue::assertPushed(SendTelegramNotificationJob::class, function ($job) {
            return $job->category === 'epoch'
                && str_contains($job->message, 'Current epoch: 14')
                && ! str_contains($job->message, 'Previous epoch:');
        });
    }

    public function test_epoch_field_change_queues_notifications(): void
    {
        TelegramChat::create([
            'chat_id' => '1002',
            'is_active' => true,
            'wants_epoch_notifications' => true,
        ]);

        app(DmdTelegramNotifier::class)->notifyEpochChange([
            'type' => 'updated',
            'previous' => [
                'staking_epoch' => 14,
                'keygen_round' => 3,
                'staking_epoch_start_time' => 1700014400,
                'staking_epoch_start_block' => 789,
                'are_stake_and_withdraw_allowed' => true,
                'staking_fixed_epoch_end_time' => 1700018000,
                'staking_fixed_epoch_duration' => 3600,
                'staking_withdraw_disallow_period' => 300,
                'delta_pot' => '10',
                'reinsert_pot' => '20',
            ],
            'current' => [
                'staking_epoch' => 14,
                'keygen_round' => 4,
                'staking_epoch_start_time' => 1700014400,
                'staking_epoch_start_block' => 789,
                'are_stake_and_withdraw_allowed' => false,
                'staking_fixed_epoch_end_time' => 1700018000,
                'staking_fixed_epoch_duration' => 3600,
                'staking_withdraw_disallow_period' => 300,
                'delta_pot' => '11000000000000000000',
                'reinsert_pot' => '1230000000000000000',
            ],
            'fields' => [
                'keygen_round' => ['from' => '3', 'to' => '4'],
                'are_stake_and_withdraw_allowed' => ['from' => '1', 'to' => ''],
                'delta_pot' => ['from' => '10', 'to' => '11000000000000000000'],
                'reinsert_pot' => ['from' => '20', 'to' => '1230000000000000000'],
            ],
        ], 'batch-4');

        Queue::assertPushed(SendTelegramNotificationJob::class, function ($job) {
            return $job->category === 'epoch'
                && str_contains($job->message, 'DMD epoch updated')
                && str_contains($job->message, 'Current epoch: 14')
                && str_contains($job->message, 'Keygen round: 3 -> 4')
                && str_contains($job->message, 'Stake/withdraw allowed: yes -> no')
                && str_contains($job->message, 'Delta pot: 0.0000 DMD -> 11.0000 DMD')
                && str_contains($job->message, 'Reinsert pot: 0.0000 DMD -> 1.2300 DMD');
        });
    }

    private function createSchema(): void
    {
        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 32)->unique();
            $table->string('type', 32)->nullable();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('wants_all_pool_notifications')->default(false);
            $table->boolean('wants_epoch_notifications')->default(false);
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_pool_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_chat_id')->constrained('telegram_chats')->cascadeOnDelete();
            $table->string('staking_address', 42);
            $table->boolean('notify_frontend_status')->default(true);
            $table->boolean('notify_score')->default(true);
            $table->boolean('notify_connectivity_report')->default(true);
            $table->boolean('notify_total_stake')->default(true);
            $table->timestamps();
            $table->unique(['telegram_chat_id', 'staking_address']);
        });

        Schema::create('telegram_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_chat_id')->constrained('telegram_chats')->cascadeOnDelete();
            $table->string('event_key', 191);
            $table->string('category', 32);
            $table->string('staking_address', 42)->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['telegram_chat_id', 'event_key']);
        });
    }
}
