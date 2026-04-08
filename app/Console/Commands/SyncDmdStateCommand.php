<?php

namespace App\Console\Commands;

use App\Models\DmdEpoch;
use App\Models\DmdPool;
use App\Services\BlockchainRpcService;
use App\Services\DmdTelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use kornrunner\Keccak;

class SyncDmdStateCommand extends Command
{
    protected $signature = 'app:sync-dmd-state {--rpc=} {--aggregator=}';

    protected $description = 'Sync DMD validator pools and epoch data from the aggregator contract';

    public function __construct(
        private readonly BlockchainRpcService $rpc,
        private readonly DmdTelegramNotifier $notifier
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $rpcUrl = (string) ($this->option('rpc') ?: config('dmd.rpc_url'));
        $aggregatorAddress = $this->normalizeAddress(
            (string) ($this->option('aggregator') ?: config('dmd.aggregator_address'))
        );

        if ($rpcUrl === '') {
            $this->error('Missing DMD RPC URL. Set DMD_RPC_URL or pass --rpc=');
            return self::FAILURE;
        }

        if ($aggregatorAddress === null) {
            $this->error('Missing DMD aggregator address. Set DMD_AGGREGATOR_ADDRESS or pass --aggregator=');
            return self::FAILURE;
        }

        $this->info('Fetching DMD pool and epoch state...');

        $getAllPoolsResult = $this->rpc->ethCall(
            $rpcUrl,
            $aggregatorAddress,
            $this->selector('getAllPools()')
        );
        $getGlobalsResult = $this->rpc->ethCall(
            $rpcUrl,
            $aggregatorAddress,
            $this->selector('getGlobals()')
        );

        if ($getAllPoolsResult === null || $getGlobalsResult === null) {
            $this->error('Failed to fetch aggregator state.');
            return self::FAILURE;
        }

        $allPools = $this->decodeAllPools($getAllPoolsResult);
        $globals = $this->decodeGlobals($getGlobalsResult);

        $activePoolAddrs = $this->addressSet(Arr::get($allPools, 4, []));
        $inactivePoolAddrs = $this->addressSet(Arr::get($allPools, 1, []));
        $toBeElectedPoolAddrs = $this->addressSet(Arr::get($allPools, 2, []));
        $pendingValidatorMiningAddrs = $this->addressSet(Arr::get($allPools, 5, []));
        $pendingValidatorStakingAddrs = $this->addressSet(Arr::get($allPools, 6, []));

        $allStakingAddresses = array_values(array_unique(array_merge(
            array_keys($activePoolAddrs),
            array_keys($inactivePoolAddrs),
            array_keys($toBeElectedPoolAddrs),
            array_keys($pendingValidatorStakingAddrs),
        )));

        if ($allStakingAddresses === []) {
            $this->warn('No staking addresses were returned by getAllPools().');
            return self::SUCCESS;
        }

        $getPoolsDataResult = $this->rpc->ethCall(
            $rpcUrl,
            $aggregatorAddress,
            $this->selector('getPoolsData(address[])') . $this->encodeAddressArrayArgument($allStakingAddresses)
        );

        if ($getPoolsDataResult === null) {
            $this->error('Failed to fetch pool details.');
            return self::FAILURE;
        }

        $poolData = $this->decodePoolsData($getPoolsDataResult);
        $poolRows = [];
        $syncedAt = now();
        foreach ($allStakingAddresses as $index => $stakingAddress) {
            $pool = $poolData[$index] ?? null;
            if ($pool === null) {
                continue;
            }

            $miningAddress = $pool['mining_address'];
            $isActive = isset($activePoolAddrs[$stakingAddress]);
            $isToBeElected = isset($toBeElectedPoolAddrs[$stakingAddress]);
            $isPendingValidator = isset($pendingValidatorMiningAddrs[$miningAddress])
                || isset($pendingValidatorStakingAddrs[$stakingAddress]);
            $frontendValid = $isActive || $isToBeElected || $isPendingValidator;

            $poolRows[] = [
                'staking_address' => $stakingAddress,
                'mining_address' => $miningAddress,
                'score' => $pool['score'],
                'is_active' => $isActive,
                'is_to_be_elected' => $isToBeElected,
                'is_pending_validator' => $isPendingValidator,
                'frontend_valid' => $frontendValid,
                'frontend_status' => $isActive ? 'Active' : ($frontendValid ? 'Valid' : 'Invalid'),
                'is_faulty_validator' => $pool['is_faulty_validator'],
                'connectivity_report' => $pool['connectivity_report'],
                'total_stake' => $pool['total_stake'],
                'available_since' => $pool['available_since'],
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        $epochRow = [
            'staking_epoch' => $globals['staking_epoch'],
            'keygen_round' => $globals['keygen_round'],
            'staking_epoch_start_time' => $globals['staking_epoch_start_time'],
            'staking_epoch_start_block' => $globals['staking_epoch_start_block'],
            'are_stake_and_withdraw_allowed' => $globals['are_stake_and_withdraw_allowed'],
            'staking_fixed_epoch_end_time' => $globals['staking_fixed_epoch_end_time'],
            'staking_fixed_epoch_duration' => $globals['staking_fixed_epoch_duration'],
            'staking_withdraw_disallow_period' => $globals['staking_withdraw_disallow_period'],
            'delta_pot' => $globals['delta_pot'],
            'reinsert_pot' => $globals['reinsert_pot'],
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ];

        $existingPools = DmdPool::query()
            ->whereIn('staking_address', $allStakingAddresses)
            ->get()
            ->keyBy('staking_address');
        $currentEpoch = DmdEpoch::query()
            ->latest('staking_epoch')
            ->first();

        $poolChanges = $this->detectPoolChanges($existingPools, $poolRows);
        $epochChange = $this->detectEpochChange($currentEpoch, $epochRow);

        DB::transaction(function () use ($poolRows, $allStakingAddresses, $epochRow): void {
            DmdPool::upsert(
                $poolRows,
                ['staking_address'],
                [
                    'mining_address',
                    'score',
                    'is_active',
                    'is_to_be_elected',
                    'is_pending_validator',
                    'frontend_valid',
                    'frontend_status',
                    'is_faulty_validator',
                    'connectivity_report',
                    'total_stake',
                    'available_since',
                    'updated_at',
                ]
            );

            DmdPool::query()
                ->whereNotIn('staking_address', $allStakingAddresses)
                ->delete();

            DmdEpoch::updateOrCreate(
                ['staking_epoch' => $epochRow['staking_epoch']],
                Arr::except($epochRow, ['staking_epoch', 'created_at'])
            );
        });

        $batchId = $syncedAt->format('YmdHisv');
        $this->notifier->notifyPoolChanges($poolChanges, $batchId);
        $this->notifier->notifyEpochChange($epochChange, $batchId);

        $this->info('Synced ' . count($poolRows) . ' DMD pools.');
        $this->info('Current staking epoch: ' . $epochRow['staking_epoch']);

        return self::SUCCESS;
    }

    private function selector(string $signature): string
    {
        return '0x' . substr(Keccak::hash($signature, 256), 0, 8);
    }

    private function normalizeHex(string $value): string
    {
        $trimmed = strtolower(trim($value));

        return str_starts_with($trimmed, '0x') ? substr($trimmed, 2) : $trimmed;
    }

    private function normalizeAddress(string $address): ?string
    {
        $hex = $this->normalizeHex($address);
        if ($hex === '' || strlen($hex) !== 40 || !ctype_xdigit($hex)) {
            return null;
        }

        return '0x' . $hex;
    }

    private function readWord(string $data, int $byteOffset): string
    {
        return substr($data, $byteOffset * 2, 64);
    }

    private function readDecimal(string $data, int $byteOffset): string
    {
        $word = $this->readWord($data, $byteOffset);

        return gmp_strval(gmp_init($word === '' ? '0' : $word, 16));
    }

    private function readOffset(string $data, int $byteOffset): int
    {
        return (int) $this->readDecimal($data, $byteOffset);
    }

    private function readBool(string $data, int $byteOffset): bool
    {
        return $this->readDecimal($data, $byteOffset) !== '0';
    }

    private function readAddress(string $data, int $byteOffset): string
    {
        $word = $this->readWord($data, $byteOffset);

        return '0x' . substr($word, -40);
    }

    private function unwrapRootDynamic(string $result): array
    {
        $data = $this->normalizeHex($result);
        $rootOffset = $this->readOffset($data, 0);

        return [$data, $rootOffset];
    }

    private function decodeAddressArray(string $data, int $start): array
    {
        $length = $this->readOffset($data, $start);
        $values = [];

        for ($index = 0; $index < $length; $index++) {
            $values[] = $this->readAddress($data, $start + 32 + ($index * 32));
        }

        return $values;
    }

    private function decodeAllPools(string $result): array
    {
        [$data, $tupleStart] = $this->unwrapRootDynamic($result);

        $values = [];
        for ($index = 0; $index < 7; $index++) {
            $fieldOffset = $this->readOffset($data, $tupleStart + ($index * 32));
            $values[] = $this->decodeAddressArray($data, $tupleStart + $fieldOffset);
        }

        return $values;
    }

    private function decodeGlobals(string $result): array
    {
        $data = $this->normalizeHex($result);

        return [
            'delta_pot' => $this->readDecimal($data, 0),
            'reinsert_pot' => $this->readDecimal($data, 32),
            'keygen_round' => (int) $this->readDecimal($data, 64),
            'staking_epoch' => (int) $this->readDecimal($data, 96),
            'staking_epoch_start_time' => (int) $this->readDecimal($data, 224),
            'staking_epoch_start_block' => (int) $this->readDecimal($data, 256),
            'are_stake_and_withdraw_allowed' => $this->readBool($data, 288),
            'staking_fixed_epoch_end_time' => (int) $this->readDecimal($data, 320),
            'staking_fixed_epoch_duration' => (int) $this->readDecimal($data, 352),
            'staking_withdraw_disallow_period' => (int) $this->readDecimal($data, 384),
        ];
    }

    private function encodeWord(string $hex): string
    {
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    private function encodeAddress(string $address): string
    {
        return $this->encodeWord($this->normalizeHex($address));
    }

    private function encodeAddressArrayArgument(array $addresses): string
    {
        $encoded = $this->encodeWord(dechex(32));
        $encoded .= $this->encodeWord(dechex(count($addresses)));

        foreach ($addresses as $address) {
            $encoded .= $this->encodeAddress($address);
        }

        return $encoded;
    }

    private function decodePoolsData(string $result): array
    {
        [$data, $arrayStart] = $this->unwrapRootDynamic($result);
        $length = $this->readOffset($data, $arrayStart);
        $headsStart = $arrayStart + 32;
        $rows = [];

        for ($index = 0; $index < $length; $index++) {
            $relativeOffset = $this->readOffset($data, $headsStart + ($index * 32));
            $structStart = $headsStart + $relativeOffset;

            $rows[] = [
                'mining_address' => $this->readAddress($data, $structStart),
                'available_since' => $this->readDecimal($data, $structStart + 32),
                'total_stake' => $this->readDecimal($data, $structStart + (32 * 5)),
                'is_faulty_validator' => $this->readBool($data, $structStart + (32 * 6)),
                'score' => $this->readDecimal($data, $structStart + (32 * 7)),
                'connectivity_report' => (int) $this->readDecimal($data, $structStart + (32 * 8)),
            ];
        }

        return $rows;
    }

    private function addressSet(array $addresses): array
    {
        $set = [];
        foreach ($addresses as $address) {
            $normalized = $this->normalizeAddress($address);
            if ($normalized !== null) {
                $set[$normalized] = true;
            }
        }

        return $set;
    }

    private function detectPoolChanges(Collection $existingPools, array $poolRows): array
    {
        $changes = [];

        foreach ($poolRows as $row) {
            /** @var DmdPool|null $existing */
            $existing = $existingPools->get($row['staking_address']);
            if ($existing === null) {
                continue;
            }

            $fields = [];
            foreach (['frontend_status', 'score', 'connectivity_report', 'total_stake'] as $field) {
                $previous = (string) $existing->{$field};
                $current = (string) $row[$field];

                if ($previous !== $current) {
                    $fields[$field] = [
                        'from' => $previous,
                        'to' => $current,
                    ];
                }
            }

            if ($fields !== []) {
                $changes[] = [
                    'staking_address' => $row['staking_address'],
                    'fields' => $fields,
                ];
            }
        }

        return $changes;
    }

    private function detectEpochChange(?DmdEpoch $currentEpoch, array $epochRow): array
    {
        if ($currentEpoch === null) {
            return [
                'type' => 'started',
                'previous' => null,
                'current' => [
                    'staking_epoch' => (int) $epochRow['staking_epoch'],
                    'keygen_round' => (int) $epochRow['keygen_round'],
                    'staking_epoch_start_time' => (int) $epochRow['staking_epoch_start_time'],
                    'staking_epoch_start_block' => (int) $epochRow['staking_epoch_start_block'],
                    'are_stake_and_withdraw_allowed' => (bool) $epochRow['are_stake_and_withdraw_allowed'],
                    'staking_fixed_epoch_end_time' => (int) $epochRow['staking_fixed_epoch_end_time'],
                ],
                'fields' => [],
            ];
        }

        $previous = [
            'staking_epoch' => (int) $currentEpoch->staking_epoch,
            'keygen_round' => (int) $currentEpoch->keygen_round,
            'staking_epoch_start_time' => (int) $currentEpoch->staking_epoch_start_time,
            'staking_epoch_start_block' => (int) $currentEpoch->staking_epoch_start_block,
            'are_stake_and_withdraw_allowed' => (bool) $currentEpoch->are_stake_and_withdraw_allowed,
            'staking_fixed_epoch_end_time' => (int) $currentEpoch->staking_fixed_epoch_end_time,
            'staking_fixed_epoch_duration' => (int) $currentEpoch->staking_fixed_epoch_duration,
            'staking_withdraw_disallow_period' => (int) $currentEpoch->staking_withdraw_disallow_period,
            'delta_pot' => (string) $currentEpoch->delta_pot,
            'reinsert_pot' => (string) $currentEpoch->reinsert_pot,
        ];

        $current = [
            'staking_epoch' => (int) $epochRow['staking_epoch'],
            'keygen_round' => (int) $epochRow['keygen_round'],
            'staking_epoch_start_time' => (int) $epochRow['staking_epoch_start_time'],
            'staking_epoch_start_block' => (int) $epochRow['staking_epoch_start_block'],
            'are_stake_and_withdraw_allowed' => (bool) $epochRow['are_stake_and_withdraw_allowed'],
            'staking_fixed_epoch_end_time' => (int) $epochRow['staking_fixed_epoch_end_time'],
            'staking_fixed_epoch_duration' => (int) $epochRow['staking_fixed_epoch_duration'],
            'staking_withdraw_disallow_period' => (int) $epochRow['staking_withdraw_disallow_period'],
            'delta_pot' => (string) $epochRow['delta_pot'],
            'reinsert_pot' => (string) $epochRow['reinsert_pot'],
        ];

        if ($previous['staking_epoch'] !== $current['staking_epoch']) {
            return [
                'type' => 'started',
                'previous' => $previous,
                'current' => $current,
                'fields' => [],
            ];
        }

        $fields = [];
        foreach ([
            'keygen_round',
            'staking_epoch_start_time',
            'staking_epoch_start_block',
            'are_stake_and_withdraw_allowed',
            'staking_fixed_epoch_end_time',
            'staking_fixed_epoch_duration',
            'staking_withdraw_disallow_period',
        ] as $field) {
            if ((string) $previous[$field] !== (string) $current[$field]) {
                $fields[$field] = [
                    'from' => (string) $previous[$field],
                    'to' => (string) $current[$field],
                ];
            }
        }

        if ($fields === []) {
            return [];
        }

        return [
            'type' => 'updated',
            'previous' => $previous,
            'current' => $current,
            'fields' => $fields,
        ];
    }
}
