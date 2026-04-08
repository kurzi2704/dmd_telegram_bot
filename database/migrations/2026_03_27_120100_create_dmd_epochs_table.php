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

            $table->index('staking_epoch_start_block');
            $table->index('staking_epoch_start_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dmd_epochs');
    }
};
