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

            $table->index('score');
            $table->index('frontend_status');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dmd_pools');
    }
};
