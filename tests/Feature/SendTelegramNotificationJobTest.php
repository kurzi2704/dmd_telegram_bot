<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramNotificationJob;
use App\Models\TelegramChat;
use App\Services\TelegramBotService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

class SendTelegramNotificationJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 32)->unique();
            $table->string('type', 32)->nullable();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('wants_epoch_notifications')->default(false);
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();
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

        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.base_url', 'https://api.telegram.org');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('telegram_notification_logs');
        Schema::dropIfExists('telegram_chats');

        parent::tearDown();
    }

    public function test_job_sends_message_and_stores_notification_log(): void
    {
        $chat = TelegramChat::create([
            'chat_id' => '2000',
            'is_active' => true,
        ]);

        $job = new SendTelegramNotificationJob(
            $chat->id,
            'epoch:test:15:started',
            'epoch',
            'DMD new epoch started'
        );

        $job->handle(app(TelegramBotService::class));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '2000'
                && $request['text'] === 'DMD new epoch started';
        });

        $this->assertDatabaseHas('telegram_notification_logs', [
            'telegram_chat_id' => $chat->id,
            'event_key' => 'epoch:test:15:started',
            'category' => 'epoch',
        ]);
    }

    public function test_job_marks_chat_inactive_when_user_blocked_bot(): void
    {
        $chat = TelegramChat::create([
            'chat_id' => '2000',
            'is_active' => true,
        ]);

        $this->mock(TelegramBotService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendMessageResult')
                ->once()
                ->with('2000', 'DMD new epoch started')
                ->andReturn(['sent' => false, 'blocked' => true]);
        });

        $job = new class($chat->id, 'epoch:test:16:started', 'epoch', 'DMD new epoch started') extends SendTelegramNotificationJob
        {
            public bool $released = false;

            public function release($delay = 0): void
            {
                $this->released = true;
            }
        };

        $job->handle(app(TelegramBotService::class));

        $this->assertFalse($job->released);
        $this->assertDatabaseHas('telegram_chats', [
            'id' => $chat->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing('telegram_notification_logs', [
            'telegram_chat_id' => $chat->id,
            'event_key' => 'epoch:test:16:started',
        ]);
    }

    public function test_job_marks_chat_inactive_when_user_is_deactivated(): void
    {
        $chat = TelegramChat::create([
            'chat_id' => '2001',
            'is_active' => true,
        ]);

        $this->mock(TelegramBotService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendMessageResult')
                ->once()
                ->with('2001', 'DMD new epoch started')
                ->andReturn(['sent' => false, 'blocked' => true]);
        });

        $job = new class($chat->id, 'epoch:test:17:started', 'epoch', 'DMD new epoch started') extends SendTelegramNotificationJob
        {
            public bool $released = false;

            public function release($delay = 0): void
            {
                $this->released = true;
            }
        };

        $job->handle(app(TelegramBotService::class));

        $this->assertFalse($job->released);
        $this->assertDatabaseHas('telegram_chats', [
            'id' => $chat->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing('telegram_notification_logs', [
            'telegram_chat_id' => $chat->id,
            'event_key' => 'epoch:test:17:started',
        ]);
    }
}
