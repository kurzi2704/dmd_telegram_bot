<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();

        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.base_url', 'https://api.telegram.org');
        config()->set('services.telegram.webhook_secret', 'top-secret');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('dmd_chain_statuses');
        Schema::dropIfExists('telegram_notification_logs');
        Schema::dropIfExists('telegram_pool_subscriptions');
        Schema::dropIfExists('telegram_chats');
        Schema::dropIfExists('dmd_pools');
        Schema::dropIfExists('dmd_epochs');

        parent::tearDown();
    }

    public function test_start_command_registers_chat(): void
    {
        $response = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
            ->postJson('/telegram/webhook', $this->messagePayload('/start'));

        $response->assertOk();

        $this->assertDatabaseHas('telegram_chats', [
            'chat_id' => '123456',
            'wants_all_pool_notifications' => false,
            'username' => 'poolwatcher',
            'wants_epoch_notifications' => false,
        ]);
    }

    public function test_follow_command_stores_multiple_subscriptions(): void
    {
        $response = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
            ->postJson('/telegram/webhook', $this->messagePayload(
                '/follow 0x1111111111111111111111111111111111111111,0x2222222222222222222222222222222222222222'
            ));

        $response->assertOk();

        $this->assertSame(2, DB::table('telegram_pool_subscriptions')->count());
        $this->assertDatabaseHas('telegram_pool_subscriptions', [
            'staking_address' => '0x1111111111111111111111111111111111111111',
        ]);
        $this->assertDatabaseHas('telegram_pool_subscriptions', [
            'staking_address' => '0x2222222222222222222222222222222222222222',
        ]);
    }

    public function test_follow_command_accepts_next_message_after_prompting_for_address(): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
            ->postJson('/telegram/webhook', $this->messagePayload('/follow'))
            ->assertOk();

        $this->assertDatabaseHas('telegram_chats', [
            'chat_id' => '123456',
            'pending_command' => '/follow',
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
            ->postJson('/telegram/webhook', $this->messagePayload('0x1111111111111111111111111111111111111111'))
            ->assertOk();

        $this->assertDatabaseHas('telegram_pool_subscriptions', [
            'staking_address' => '0x1111111111111111111111111111111111111111',
        ]);
        $this->assertDatabaseHas('telegram_chats', [
            'chat_id' => '123456',
            'pending_command' => null,
        ]);
    }

    public function test_follow_all_argument_enables_all_pool_notifications(): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
            ->postJson('/telegram/webhook', $this->messagePayload('/follow all'))
            ->assertOk();

        $this->assertDatabaseHas('telegram_chats', [
            'chat_id' => '123456',
            'wants_all_pool_notifications' => true,
        ]);
    }

    public function test_followall_command_enables_all_pool_notifications(): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
            ->postJson('/telegram/webhook', $this->messagePayload('/followall'))
            ->assertOk();

        $this->assertDatabaseHas('telegram_chats', [
            'chat_id' => '123456',
            'wants_all_pool_notifications' => true,
        ]);
    }

    public function test_unfollow_all_argument_disables_all_pool_notifications_and_keeps_manual_follows(): void
    {
        DB::table('telegram_chats')->insert([
            'id' => 1,
            'chat_id' => '123456',
            'type' => 'private',
            'username' => 'poolwatcher',
            'first_name' => 'Pool',
            'last_name' => 'Watcher',
            'is_active' => true,
            'wants_all_pool_notifications' => true,
            'wants_epoch_notifications' => false,
            'pending_command' => null,
            'last_interaction_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('telegram_pool_subscriptions')->insert([
            'telegram_chat_id' => 1,
            'staking_address' => '0x1111111111111111111111111111111111111111',
            'notify_frontend_status' => true,
            'notify_score' => true,
            'notify_connectivity_report' => true,
            'notify_total_stake' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
            ->postJson('/telegram/webhook', $this->messagePayload('/unfollow all'))
            ->assertOk();

        $this->assertDatabaseHas('telegram_chats', [
            'chat_id' => '123456',
            'wants_all_pool_notifications' => false,
        ]);
        $this->assertDatabaseHas('telegram_pool_subscriptions', [
            'telegram_chat_id' => 1,
            'staking_address' => '0x1111111111111111111111111111111111111111',
        ]);
    }

    public function test_epochs_command_updates_opt_in_flag(): void
    {
        $response = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
            ->postJson('/telegram/webhook', $this->messagePayload('/epochs on'));

        $response->assertOk();

        $this->assertDatabaseHas('telegram_chats', [
            'chat_id' => '123456',
            'wants_epoch_notifications' => true,
        ]);
    }

    public function test_list_command_sends_validator_table_with_score(): void
    {
        DB::table('telegram_chats')->insert([
            'id' => 1,
            'chat_id' => '123456',
            'type' => 'private',
            'username' => 'poolwatcher',
            'first_name' => 'Pool',
            'last_name' => 'Watcher',
            'is_active' => true,
            'wants_all_pool_notifications' => true,
            'wants_epoch_notifications' => true,
            'pending_command' => null,
            'last_interaction_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dmd_pools')->insert([
            'staking_address' => '0x1111111111111111111111111111111111111111',
            'mining_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'score' => '42',
            'frontend_status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('telegram_pool_subscriptions')->insert([
            'telegram_chat_id' => 1,
            'staking_address' => '0x1111111111111111111111111111111111111111',
            'notify_frontend_status' => true,
            'notify_score' => true,
            'notify_connectivity_report' => true,
            'notify_total_stake' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
            ->postJson('/telegram/webhook', $this->messagePayload('/list'))
            ->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '123456'
                && $request['parse_mode'] === 'HTML'
                && str_contains($request['text'], 'All validator notifications: on')
                && str_contains($request['text'], 'Score')
                && str_contains($request['text'], 'Active')
                && str_contains($request['text'], '42')
                && str_contains($request['text'], '0x1111...111111');
        });
    }

    public function test_epoch_command_sends_current_epoch(): void
    {
        DB::table('dmd_epochs')->insert([
            'staking_epoch' => 15,
            'keygen_round' => 2,
            'staking_epoch_start_time' => 1700007200,
            'staking_epoch_start_block' => 456,
            'are_stake_and_withdraw_allowed' => true,
            'staking_fixed_epoch_end_time' => 1700093600,
            'staking_fixed_epoch_duration' => 86400,
            'staking_withdraw_disallow_period' => 3600,
            'delta_pot' => '1000000000000000000',
            'reinsert_pot' => '500000000000000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
            ->postJson('/telegram/webhook', $this->messagePayload('/epoch'))
            ->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '123456'
                && $request['text'] === implode("\n", [
                    'Current epoch: 15',
                    'Keygen round: 2',
                    'Stake/withdraw allowed: yes',
                    'Epoch start block: 456',
                    'Epoch start time: 2023-11-15 00:13:20 UTC',
                    'Epoch end time: 2023-11-16 00:13:20 UTC',
                ]);
        });
    }

    public function test_status_command_sends_network_summary(): void
    {
        DB::table('telegram_chats')->insert([
            'id' => 1,
            'chat_id' => '123456',
            'type' => 'private',
            'username' => 'poolwatcher',
            'first_name' => 'Pool',
            'last_name' => 'Watcher',
            'is_active' => true,
            'wants_all_pool_notifications' => true,
            'wants_epoch_notifications' => true,
            'pending_command' => null,
            'last_interaction_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('telegram_pool_subscriptions')->insert([
            'telegram_chat_id' => 1,
            'staking_address' => '0x1111111111111111111111111111111111111111',
            'notify_frontend_status' => true,
            'notify_score' => true,
            'notify_connectivity_report' => true,
            'notify_total_stake' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dmd_pools')->insert([
            [
                'staking_address' => '0x1111111111111111111111111111111111111111',
                'mining_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'score' => '42',
                'is_active' => true,
                'is_to_be_elected' => false,
                'is_pending_validator' => false,
                'frontend_valid' => true,
                'frontend_status' => 'Active',
                'is_faulty_validator' => false,
                'connectivity_report' => 0,
                'total_stake' => '1000000000000000000',
                'available_since' => 1700000000,
                'created_at' => now()->subMinutes(6),
                'updated_at' => now()->subMinutes(4),
            ],
            [
                'staking_address' => '0x2222222222222222222222222222222222222222',
                'mining_address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'score' => '13',
                'is_active' => false,
                'is_to_be_elected' => true,
                'is_pending_validator' => true,
                'frontend_valid' => true,
                'frontend_status' => 'Valid',
                'is_faulty_validator' => true,
                'connectivity_report' => 2,
                'total_stake' => '2000000000000000000',
                'available_since' => 1700001000,
                'created_at' => now()->subMinutes(6),
                'updated_at' => now()->subMinutes(3),
            ],
        ]);

        DB::table('dmd_epochs')->insert([
            'staking_epoch' => 15,
            'keygen_round' => 2,
            'staking_epoch_start_time' => 1700007200,
            'staking_epoch_start_block' => 456,
            'are_stake_and_withdraw_allowed' => true,
            'staking_fixed_epoch_end_time' => 1700093600,
            'staking_fixed_epoch_duration' => 86400,
            'staking_withdraw_disallow_period' => 3600,
            'delta_pot' => '1000000000000000000',
            'reinsert_pot' => '500000000000000000',
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(2),
        ]);

        DB::table('dmd_chain_statuses')->insert([
            'network' => 'mainnet',
            'latest_block_number' => 987654,
            'latest_block_timestamp' => now()->subSeconds(45),
            'last_rpc_check_at' => now()->subSeconds(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
            ->postJson('/telegram/webhook', $this->messagePayload('/status'))
            ->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '123456'
                && $request['parse_mode'] === 'HTML'
                && str_contains($request['text'], '<b>DMD Status</b>')
                && str_contains($request['text'], '<b>Chain</b>')
                && str_contains($request['text'], '<b>Validator Set</b>')
                && str_contains($request['text'], '<b>Current Epoch</b>')
                && str_contains($request['text'], '<b>Your Alerts</b>')
                && str_contains($request['text'], 'Total: 2')
                && str_contains($request['text'], 'Active: 1')
                && str_contains($request['text'], 'Valid: 1')
                && str_contains($request['text'], 'Invalid: 0')
                && str_contains($request['text'], 'Latest block: 987654')
                && str_contains($request['text'], 'Block age:')
                && str_contains($request['text'], 'RPC checked:')
                && str_contains($request['text'], 'Epoch: 15')
                && str_contains($request['text'], 'Start block: 456')
                && str_contains($request['text'], 'Start: 2023-11-15 00:13:20 UTC')
                && str_contains($request['text'], 'End: 2023-11-16 00:13:20 UTC')
                && str_contains($request['text'], 'Follow all: on')
                && str_contains($request['text'], 'Epoch alerts: on')
                && str_contains($request['text'], 'Manual follows: 1')
                && str_contains($request['text'], 'Snapshot age:');
        });
    }

    private function messagePayload(string $text): array
    {
        return [
            'message' => [
                'message_id' => 1,
                'text' => $text,
                'chat' => [
                    'id' => 123456,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => 123456,
                    'is_bot' => false,
                    'first_name' => 'Pool',
                    'last_name' => 'Watcher',
                    'username' => 'poolwatcher',
                ],
            ],
        ];
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
            $table->string('pending_command', 32)->nullable();
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

        Schema::create('dmd_pools', function (Blueprint $table) {
            $table->id();
            $table->string('staking_address', 42)->unique();
            $table->string('mining_address', 42)->unique();
            $table->decimal('score', 65, 0)->default(0);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_to_be_elected')->default(false);
            $table->boolean('is_pending_validator')->default(false);
            $table->boolean('frontend_valid')->default(false);
            $table->string('frontend_status', 32)->default('Invalid');
            $table->boolean('is_faulty_validator')->default(false);
            $table->unsignedInteger('connectivity_report')->default(0);
            $table->decimal('total_stake', 65, 0)->default(0);
            $table->unsignedBigInteger('available_since')->nullable();
            $table->timestamps();
        });

        Schema::create('dmd_epochs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('staking_epoch')->unique();
            $table->unsignedInteger('keygen_round')->default(0);
            $table->unsignedBigInteger('staking_epoch_start_time');
            $table->unsignedBigInteger('staking_epoch_start_block');
            $table->boolean('are_stake_and_withdraw_allowed')->default(false);
            $table->unsignedBigInteger('staking_fixed_epoch_end_time');
            $table->unsignedBigInteger('staking_fixed_epoch_duration');
            $table->unsignedBigInteger('staking_withdraw_disallow_period')->default(0);
            $table->decimal('delta_pot', 65, 0)->default(0);
            $table->decimal('reinsert_pot', 65, 0)->default(0);
            $table->timestamps();
        });

        Schema::create('dmd_chain_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('network', 32)->unique();
            $table->unsignedBigInteger('latest_block_number')->nullable();
            $table->timestamp('latest_block_timestamp')->nullable();
            $table->timestamp('last_rpc_check_at')->nullable();
            $table->timestamps();
        });
    }
}
