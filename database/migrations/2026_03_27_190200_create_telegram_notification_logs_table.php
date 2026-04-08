<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('telegram_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_chat_id')
                ->constrained(table: 'telegram_chats', indexName: 'tnl_chat_fk')
                ->cascadeOnDelete();
            $table->string('event_key', 191);
            $table->string('category', 32);
            $table->string('staking_address', 42)->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['telegram_chat_id', 'event_key'], 'tnl_chat_event_uniq');
            $table->index(['category', 'staking_address'], 'tnl_category_staking_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_notification_logs');
    }
};
