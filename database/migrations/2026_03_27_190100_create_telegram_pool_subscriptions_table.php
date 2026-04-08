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
        Schema::create('telegram_pool_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_chat_id')
                ->constrained(table: 'telegram_chats', indexName: 'tps_chat_fk')
                ->cascadeOnDelete();
            $table->string('staking_address', 42);
            $table->boolean('notify_frontend_status')->default(true);
            $table->boolean('notify_score')->default(true);
            $table->boolean('notify_connectivity_report')->default(true);
            $table->boolean('notify_total_stake')->default(true);
            $table->timestamps();

            $table->unique(['telegram_chat_id', 'staking_address'], 'tps_chat_staking_uniq');
            $table->index('staking_address', 'tps_staking_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_pool_subscriptions');
    }
};
