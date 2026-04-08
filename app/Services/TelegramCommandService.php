<?php

namespace App\Services;

use App\Models\DmdEpoch;
use App\Models\DmdPool;
use App\Models\DmdChainStatus;
use App\Models\TelegramChat;
use App\Models\TelegramPoolSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TelegramCommandService
{
    public function __construct(private readonly TelegramBotService $bot)
    {
    }

    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        $text = trim((string) data_get($message, 'text', ''));
        $chatId = (string) data_get($message, 'chat.id', '');

        if ($chatId === '' || $text === '') {
            return;
        }

        $chat = TelegramChat::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'type' => data_get($message, 'chat.type'),
                'username' => data_get($message, 'from.username'),
                'first_name' => data_get($message, 'from.first_name'),
                'last_name' => data_get($message, 'from.last_name'),
                'is_active' => true,
                'last_interaction_at' => now(),
            ]
        );

        [$command, $arguments] = $this->parseCommand($text);
        [$command, $arguments] = $this->applyPendingCommand($chat, $text, $command, $arguments);

        $reply = match ($command) {
            '/start', '/help' => $this->helpMessage($chat),
            '/follow' => $this->followPools($chat, $arguments),
            '/followall' => $this->followAllPools($chat),
            '/unfollow' => $this->unfollowPools($chat, $arguments),
            '/unfollowall' => $this->unfollowAllPools($chat),
            '/list' => $this->listSubscriptions($chat),
            '/status' => $this->statusSummary($chat),
            '/epoch' => $this->currentEpoch(),
            '/epochs' => $this->toggleEpochNotifications($chat, $arguments),
            default => $this->unknownCommandMessage(),
        };

        $this->bot->sendMessage(
            $chat->chat_id,
            $reply['text'],
            $reply['parse_mode'] ?? null,
        );
    }

    private function applyPendingCommand(
        TelegramChat $chat,
        string $text,
        string $command,
        string $arguments
    ): array {
        if (! str_starts_with($text, '/')) {
            if (in_array($chat->pending_command, ['/follow', '/unfollow'], true)) {
                return [$chat->pending_command, $text];
            }

            return [$command, $arguments];
        }

        if (! in_array($command, ['/follow', '/unfollow'], true) && $chat->pending_command !== null) {
            $chat->forceFill(['pending_command' => null])->save();
        }

        return [$command, $arguments];
    }

    private function parseCommand(string $text): array
    {
        $parts = preg_split('/\s+/', trim($text), 2) ?: [];
        $command = strtolower((string) ($parts[0] ?? ''));
        $command = explode('@', $command)[0];
        $arguments = trim((string) ($parts[1] ?? ''));

        return [$command, $arguments];
    }

    private function helpMessage(TelegramChat $chat): array
    {
        $poolCount = $chat->poolSubscriptions()->count();
        $allPoolsStatus = $chat->wants_all_pool_notifications ? 'on' : 'off';
        $epochStatus = $chat->wants_epoch_notifications ? 'on' : 'off';

        return $this->plainTextReply(implode("\n", [
            'DMD validator watcher is connected.',
            '',
            'Commands:',
            '/follow <validator_wallet_address ...>',
            '/follow all',
            '/followall',
            '/unfollow <validator_wallet_address ...>',
            '/unfollow all',
            '/unfollowall',
            '/status',
            '/list',
            '/epoch',
            '/epochs on|off',
            '/help',
            '',
            'Tips:',
            '- Separate multiple validator wallet addresses with spaces, commas, or new lines.',
            '- Each followed validator notifies on status, score, connectivity report, and total stake changes.',
            '- Follow-all mode automatically includes new validators and sends batched updates per sync.',
            '',
            'All validator notifications: ' . $allPoolsStatus,
            'Current validator subscriptions: ' . $poolCount,
            'Epoch notifications: ' . $epochStatus,
        ]));
    }

    private function followPools(TelegramChat $chat, string $arguments): array
    {
        if (strtolower(trim($arguments)) === 'all') {
            return $this->followAllPools($chat);
        }

        $addresses = $this->extractAddresses($arguments);
        if ($addresses === []) {
            $chat->forceFill(['pending_command' => '/follow'])->save();

            return $this->plainTextReply(
                'Reply with one or more validator wallet addresses to follow. You can send them separated by spaces, commas, or new lines.'
            );
        }

        $this->clearPendingCommand($chat, '/follow');

        $existingPools = DmdPool::query()
            ->whereIn('staking_address', $addresses)
            ->pluck('staking_address')
            ->all();

        $created = [];
        foreach ($addresses as $address) {
            TelegramPoolSubscription::firstOrCreate(
                [
                    'telegram_chat_id' => $chat->id,
                    'staking_address' => $address,
                ],
                [
                    'notify_frontend_status' => true,
                    'notify_score' => true,
                    'notify_connectivity_report' => true,
                    'notify_total_stake' => true,
                ]
            );

            $created[] = $address;
        }

        $unknown = array_values(array_diff($addresses, $existingPools));
        $message = [
            'Following ' . count($created) . ' validator wallet address(es).',
        ];

        if ($unknown !== []) {
            $message[] = '';
            $message[] = 'Not currently present in list of validators, but saved anyway:';
            $message = array_merge($message, $unknown);
        }

        return $this->plainTextReply(implode("\n", $message));
    }

    private function followAllPools(TelegramChat $chat): array
    {
        $chat->forceFill(['wants_all_pool_notifications' => true])->save();

        return $this->plainTextReply(
            'All validator notifications are now on. New validators will be included automatically, and updates will be batched per sync.'
        );
    }

    private function unfollowPools(TelegramChat $chat, string $arguments): array
    {
        if (strtolower(trim($arguments)) === 'all') {
            return $this->unfollowAllPools($chat);
        }

        $addresses = $this->extractAddresses($arguments);
        if ($addresses === []) {
            $chat->forceFill(['pending_command' => '/unfollow'])->save();

            return $this->plainTextReply(
                'Reply with one or more validator wallet addresses to unfollow. You can send them separated by spaces, commas, or new lines.'
            );
        }

        $this->clearPendingCommand($chat, '/unfollow');

        $deleted = TelegramPoolSubscription::query()
            ->where('telegram_chat_id', $chat->id)
            ->whereIn('staking_address', $addresses)
            ->delete();

        return $this->plainTextReply('Removed ' . $deleted . ' validator subscription(s).');
    }

    private function unfollowAllPools(TelegramChat $chat): array
    {
        $chat->forceFill(['wants_all_pool_notifications' => false])->save();

        return $this->plainTextReply(
            'All validator notifications are now off. Your individually followed validator subscriptions were kept.'
        );
    }

    private function listSubscriptions(TelegramChat $chat): array
    {
        /** @var Collection<int, string> $addresses */
        $addresses = $chat->poolSubscriptions()
            ->orderBy('staking_address')
            ->pluck('staking_address');

        $lines = [
            'Validator subscriptions',
            '',
            'All validator notifications: ' . ($chat->wants_all_pool_notifications ? 'on' : 'off'),
            'Epoch notifications: ' . ($chat->wants_epoch_notifications ? 'on' : 'off'),
            'Total: ' . $addresses->count(),
        ];

        if ($addresses->isNotEmpty()) {
            $poolsByAddress = DmdPool::query()
                ->whereIn('staking_address', $addresses->all())
                ->get()
                ->keyBy('staking_address');

            $tableLines = [];
            $tableLines[] = sprintf('%-15s %-16s %s', 'Address', 'Status', 'Score');
            $tableLines[] = sprintf('%-15s %-16s %s', str_repeat('-', 15), str_repeat('-', 16), str_repeat('-', 12));

            foreach ($addresses as $address) {
                $pool = $poolsByAddress->get($address);
                $score = $pool === null ? '-' : (string) $pool->score;
                $status = $pool === null ? 'unknown' : (string) $pool->frontend_status;

                $tableLines[] = sprintf(
                    '%-15s %-16s %s',
                    $this->truncateAddress($address),
                    Str::limit($status, 16, ''),
                    Str::limit($score, 12, '')
                );
            }

            $lines[] = '';
            $lines[] = '<pre>' . e(implode("\n", $tableLines)) . '</pre>';
        }

        return $this->htmlReply(implode("\n", $lines));
    }

    private function statusSummary(TelegramChat $chat): array
    {
        $epoch = DmdEpoch::query()
            ->latest('staking_epoch')
            ->first();
        $poolQuery = DmdPool::query();
        $totalValidators = (clone $poolQuery)->count();
        $activeValidators = (clone $poolQuery)->where('is_active', true)->count();
        $validValidators = (clone $poolQuery)
            ->where('frontend_valid', true)
            ->where('is_active', false)
            ->count();
        $invalidValidators = (clone $poolQuery)->where('frontend_valid', false)->count();

        $lastPoolSync = DmdPool::query()->max('updated_at');
        $lastEpochSync = DmdEpoch::query()->max('updated_at');
        $chainStatus = DmdChainStatus::query()
            ->where('network', 'mainnet')
            ->first();
        $lastSync = collect([$lastPoolSync, $lastEpochSync])
            ->filter()
            ->map(fn ($value) => \Illuminate\Support\Carbon::parse($value))
            ->sortDesc()
            ->first();

        $lines = [
            '<b>DMD Status</b>',
            '',
            '<b>Chain</b>',
            'Latest block: ' . ($chainStatus?->latest_block_number ?? 'unknown'),
            'Block age: ' . ($chainStatus?->latest_block_timestamp ? $chainStatus->latest_block_timestamp->diffForHumans(null, true) : 'unknown'),
            'RPC checked: ' . ($chainStatus?->last_rpc_check_at ? $chainStatus->last_rpc_check_at->diffForHumans(null, true) . ' ago' : 'unknown'),
            '',
            '<b>Validator Set</b>',
            'Total: ' . $totalValidators,
            'Active: ' . $activeValidators,
            'Valid: ' . $validValidators,
            'Invalid: ' . $invalidValidators,
            '',
            '<b>Current Epoch</b>',
        ];

        if ($epoch instanceof DmdEpoch) {
            $lines = array_merge($lines, [
                'Epoch: ' . $epoch->staking_epoch,
                'Keygen round: ' . $epoch->keygen_round,
                'Stake/withdraw: ' . ($epoch->are_stake_and_withdraw_allowed ? 'yes' : 'no'),
                'Start: ' . $this->formatUnixTimestamp((int) $epoch->staking_epoch_start_time),
                'End: ' . $this->formatUnixTimestamp((int) $epoch->staking_fixed_epoch_end_time),
                'Start block: ' . $epoch->staking_epoch_start_block,
            ]);
        } else {
            $lines[] = 'No epoch data available.';
        }

        $lines = array_merge($lines, [
            '',
            '<b>Your Alerts</b>',
            'Follow all: ' . ($chat->wants_all_pool_notifications ? 'on' : 'off'),
            'Epoch alerts: ' . ($chat->wants_epoch_notifications ? 'on' : 'off'),
            'Manual follows: ' . $chat->poolSubscriptions()->count(),
            '',
            'Snapshot age: ' . ($lastSync ? $lastSync->diffForHumans(null, true) : 'unknown'),
        ]);

        return $this->htmlReply(implode("\n", $lines));
    }

    private function currentEpoch(): array
    {
        $epoch = DmdEpoch::query()
            ->latest('staking_epoch')
            ->first();

        if (! $epoch instanceof DmdEpoch) {
            return $this->plainTextReply('No epoch data is available yet.');
        }

        return $this->plainTextReply(implode("\n", [
            'Current epoch: ' . $epoch->staking_epoch,
            'Keygen round: ' . $epoch->keygen_round,
            'Stake/withdraw allowed: ' . ($epoch->are_stake_and_withdraw_allowed ? 'yes' : 'no'),
            'Epoch start block: ' . $epoch->staking_epoch_start_block,
            'Epoch start time: ' . $this->formatUnixTimestamp((int) $epoch->staking_epoch_start_time),
            'Epoch end time: ' . $this->formatUnixTimestamp((int) $epoch->staking_fixed_epoch_end_time),
        ]));
    }

    private function toggleEpochNotifications(TelegramChat $chat, string $arguments): array
    {
        $value = strtolower(trim($arguments));
        if (! in_array($value, ['on', 'off'], true)) {
            return $this->plainTextReply('Use /epochs on or /epochs off.');
        }

        $chat->forceFill([
            'wants_epoch_notifications' => $value === 'on',
        ])->save();

        return $this->plainTextReply('Epoch notifications are now ' . $value . '.');
    }

    private function unknownCommandMessage(): array
    {
        return $this->plainTextReply('Unknown command. Use /help to see the available commands.');
    }

    private function extractAddresses(string $arguments): array
    {
        $parts = preg_split('/[\s,]+/', trim($arguments), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $addresses = [];

        foreach ($parts as $part) {
            $normalized = $this->normalizeAddress($part);
            if ($normalized !== null) {
                $addresses[$normalized] = $normalized;
            }
        }

        return array_values($addresses);
    }

    private function normalizeAddress(string $value): ?string
    {
        $trimmed = strtolower(trim($value));
        if (! str_starts_with($trimmed, '0x')) {
            return null;
        }

        if (strlen($trimmed) !== 42) {
            return null;
        }

        $hex = substr($trimmed, 2);

        return ctype_xdigit($hex) ? $trimmed : null;
    }

    private function clearPendingCommand(TelegramChat $chat, string $command): void
    {
        if ($chat->pending_command !== $command) {
            return;
        }

        $chat->forceFill(['pending_command' => null])->save();
    }

    private function plainTextReply(string $text): array
    {
        return ['text' => $text];
    }

    private function htmlReply(string $text): array
    {
        return [
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
    }

    private function truncateAddress(string $address): string
    {
        if (strlen($address) <= 15) {
            return $address;
        }

        return substr($address, 0, 6) . '...' . substr($address, -6);
    }

    private function formatUnixTimestamp(int $timestamp): string
    {
        return now()->setTimestamp($timestamp)->utc()->toDateTimeString() . ' UTC';
    }
}
