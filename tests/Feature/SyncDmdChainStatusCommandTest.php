<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncDmdChainStatusCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dmd_chain_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('network', 32)->unique();
            $table->unsignedBigInteger('latest_block_number')->nullable();
            $table->timestamp('latest_block_timestamp')->nullable();
            $table->timestamp('last_rpc_check_at')->nullable();
            $table->timestamps();
        });

        config()->set('dmd.rpc_url', 'https://rpc.bit.diamonds');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('dmd_chain_statuses');

        parent::tearDown();
    }

    public function test_command_syncs_latest_dmd_block_status(): void
    {
        Http::fake([
            'https://rpc.bit.diamonds' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0xf1206'], 200)
                ->push([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'number' => '0xf1206',
                        'timestamp' => '0x6553f100',
                    ],
                ], 200),
        ]);

        $this->artisan('app:sync-dmd-chain-status')
            ->assertSuccessful();

        $this->assertDatabaseHas('dmd_chain_statuses', [
            'network' => 'mainnet',
            'latest_block_number' => 987654,
        ]);

        $this->assertDatabaseHas('dmd_chain_statuses', [
            'network' => 'mainnet',
            'latest_block_timestamp' => '2023-11-14 22:13:20',
        ]);
    }
}
