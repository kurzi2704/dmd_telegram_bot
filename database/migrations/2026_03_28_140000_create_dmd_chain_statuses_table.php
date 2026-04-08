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
        Schema::create('dmd_chain_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('network', 32)->unique();
            $table->unsignedBigInteger('latest_block_number')->nullable();
            $table->timestamp('latest_block_timestamp')->nullable();
            $table->timestamp('last_rpc_check_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dmd_chain_statuses');
    }
};
