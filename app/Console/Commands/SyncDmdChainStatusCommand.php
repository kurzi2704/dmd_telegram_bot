<?php

namespace App\Console\Commands;

use App\Models\DmdChainStatus;
use App\Services\BlockchainRpcService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncDmdChainStatusCommand extends Command
{
    protected $signature = 'app:sync-dmd-chain-status {--rpc=}';

    protected $description = 'Sync latest DMD block freshness information from RPC';

    public function __construct(private readonly BlockchainRpcService $rpc)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rpcUrl = (string) ($this->option('rpc') ?: config('dmd.rpc_url'));

        if ($rpcUrl === '') {
            $this->error('Missing DMD RPC URL. Set DMD_RPC_URL or pass --rpc=');

            return self::FAILURE;
        }

        $latestBlockNumber = $this->rpc->getLatestBlock($rpcUrl);
        if ($latestBlockNumber <= 0) {
            $this->error('Failed to fetch latest DMD block number.');

            return self::FAILURE;
        }

        $block = $this->rpc->getBlockByNumber($rpcUrl, $latestBlockNumber);
        $timestampHex = is_array($block) ? ($block['timestamp'] ?? null) : null;

        if (! is_string($timestampHex) || ! str_starts_with($timestampHex, '0x')) {
            $this->error('Failed to fetch latest DMD block timestamp.');

            return self::FAILURE;
        }

        $blockTimestamp = Carbon::createFromTimestampUTC(hexdec($timestampHex));

        DmdChainStatus::updateOrCreate(
            ['network' => 'mainnet'],
            [
                'latest_block_number' => $latestBlockNumber,
                'latest_block_timestamp' => $blockTimestamp,
                'last_rpc_check_at' => now(),
            ]
        );

        $this->info('Synced DMD chain status for block ' . $latestBlockNumber . '.');

        return self::SUCCESS;
    }
}
