<?php

namespace App\Services;

use App\Jobs\SendTelegramNotificationJob;
use App\Models\DmdPool;
use App\Models\TelegramChat;
use App\Models\TelegramPoolSubscription;
use Illuminate\Support\Str;

class DmdTelegramNotifier
{
    private const TELEGRAM_MESSAGE_LIMIT = 4000;

    public function __construct(private readonly TelegramBotService $bot)
    {
    }

    public function notifyPoolChanges(array $changes, string $batchId): void
    {
        if ($changes === [] || ! $this->bot->isConfigured()) {
            return;
        }

        $addresses = array_values(array_unique(array_column($changes, 'staking_address')));
        $subscriptions = TelegramPoolSubscription::query()
            ->with('chat')
            ->whereIn('staking_address', $addresses)
            ->get();
        $allPoolChats = TelegramChat::query()
            ->where('is_active', true)
            ->where('wants_all_pool_notifications', true)
            ->get();
        $subscriptionsByChat = TelegramPoolSubscription::query()
            ->whereIn('telegram_chat_id', $allPoolChats->pluck('id')->all())
            ->whereIn('staking_address', $addresses)
            ->get()
            ->groupBy('telegram_chat_id')
            ->map(fn ($items) => $items->pluck('staking_address')->flip()->all());

        $changesByAddress = [];
        foreach ($changes as $change) {
            $changesByAddress[$change['staking_address']] = $change;
        }

        $dispatchIndex = 0;
        foreach ($allPoolChats as $chat) {
            $highlightedAddresses = $subscriptionsByChat->get($chat->id, []);

            foreach ($this->allPoolChangeMessages($changes, $highlightedAddresses) as $messageIndex => $message) {
                SendTelegramNotificationJob::dispatch(
                    $chat->id,
                    'pool-all:' . $batchId . ':' . $messageIndex,
                    'pool',
                    $message
                )->delay($this->messageDelay($dispatchIndex));
                $dispatchIndex++;
            }
        }

        foreach ($subscriptions as $subscription) {
            $chat = $subscription->chat;
            if (! $chat instanceof TelegramChat || ! $chat->is_active) {
                continue;
            }

            if ($chat->wants_all_pool_notifications) {
                continue;
            }

            $change = $changesByAddress[$subscription->staking_address] ?? null;
            if ($change === null) {
                continue;
            }

            $lines = $this->poolChangeLines($subscription, $change);
            if ($lines === []) {
                continue;
            }

            $eventKey = 'pool:' . $batchId . ':' . $subscription->staking_address;
            $message = implode("\n", array_merge([
                'DMD validator update',
                'Validator wallet address: ' . $subscription->staking_address,
            ], [''], $lines));

            SendTelegramNotificationJob::dispatch(
                $chat->id,
                $eventKey,
                'pool',
                $message,
                ['staking_address' => $subscription->staking_address]
            )->delay($this->messageDelay($dispatchIndex));
            $dispatchIndex++;
        }
    }

    public function notifyEpochChange(array $change, string $batchId): void
    {
        if ($change === [] || ! $this->bot->isConfigured()) {
            return;
        }

        $chats = TelegramChat::query()
            ->where('is_active', true)
            ->where('wants_epoch_notifications', true)
            ->get();
        $activeValidators = DmdPool::query()
            ->where('is_active', true)
            ->count();

        $dispatchIndex = 0;
        foreach ($chats as $chat) {
            $eventKey = 'epoch:' . $batchId . ':' . $change['current']['staking_epoch'] . ':' . ($change['type'] ?? 'updated');
            $messageLines = [
                ($change['type'] ?? 'updated') === 'started' ? 'DMD new epoch started' : 'DMD epoch updated',
            ];

            if (($change['type'] ?? 'updated') === 'started' && is_array($change['previous'] ?? null)) {
                $messageLines[] = 'Previous epoch: ' . $change['previous']['staking_epoch'];
            }

            $messageLines = array_merge($messageLines, [
                'Current epoch: ' . $change['current']['staking_epoch'],
                'Keygen round: ' . $change['current']['keygen_round'],
                'Active validators: ' . $activeValidators,
                'Stake/withdraw allowed: ' . ($change['current']['are_stake_and_withdraw_allowed'] ? 'yes' : 'no'),
                'Epoch start block: ' . $change['current']['staking_epoch_start_block'],
                'Epoch start time: ' . $this->formatUnixTimestamp($change['current']['staking_epoch_start_time']),
                'Epoch end time: ' . $this->formatUnixTimestamp($change['current']['staking_fixed_epoch_end_time']),
            ]);

            if (($change['type'] ?? 'updated') === 'updated') {
                $messageLines[] = '';
                foreach ($this->epochFieldLines($change['fields'] ?? []) as $line) {
                    $messageLines[] = $line;
                }
            }

            $message = implode("\n", $messageLines);

            SendTelegramNotificationJob::dispatch(
                $chat->id,
                $eventKey,
                'epoch',
                $message
            )->delay($this->messageDelay($dispatchIndex));
            $dispatchIndex++;
        }
    }

    private function poolChangeLines(TelegramPoolSubscription $subscription, array $change): array
    {
        $lines = [];
        $fields = $change['fields'];

        if ($subscription->notify_frontend_status && array_key_exists('frontend_status', $fields)) {
            $lines[] = 'Status: ' . $fields['frontend_status']['from'] . ' -> ' . $fields['frontend_status']['to'];
        }

        if ($subscription->notify_score && array_key_exists('score', $fields)) {
            $lines[] = 'Score: ' . $fields['score']['from'] . ' -> ' . $fields['score']['to'];
        }

        if ($subscription->notify_connectivity_report && array_key_exists('connectivity_report', $fields)) {
            $lines[] = 'Connectivity report: ' . $fields['connectivity_report']['from'] . ' -> ' . $fields['connectivity_report']['to'];
        }

        if ($subscription->notify_total_stake && array_key_exists('total_stake', $fields)) {
            $lines[] = 'Total stake: '
                . $this->formatDmdAmount($fields['total_stake']['from'])
                . ' -> '
                . $this->formatDmdAmount($fields['total_stake']['to']);
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @param  array<string, int|string|bool>  $highlightedAddresses
     * @return array<int, string>
     */
    private function allPoolChangeMessages(array $changes, array $highlightedAddresses): array
    {
        $header = $this->batchHeaderLines($changes);
        $sections = $this->groupedPoolChangeSections($changes, $highlightedAddresses);

        $messages = [];
        $current = $header;

        foreach ($sections as $section) {
            $sectionLines = array_merge([''], $section);
            $candidate = implode("\n", array_merge($current, $sectionLines));

            if (strlen($candidate) > self::TELEGRAM_MESSAGE_LIMIT && count($current) > count($header)) {
                $messages[] = implode("\n", $current);
                $current = array_merge($header, $sectionLines);

                continue;
            }

            $current = array_merge($current, $sectionLines);
        }

        if (count($current) > count($header)) {
            $messages[] = implode("\n", $current);
        }

        return $messages;
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, string>
     */
    private function batchHeaderLines(array $changes): array
    {
        $toActive = 0;
        $toValid = 0;
        $toInvalid = 0;
        $otherStatus = 0;
        $connectivity = 0;
        $score = 0;
        $stake = 0;

        foreach ($changes as $change) {
            $fields = $change['fields'];

            if (array_key_exists('frontend_status', $fields)) {
                $from = $fields['frontend_status']['from'];
                $to = $fields['frontend_status']['to'];

                if ($to === 'Invalid') {
                    $toInvalid++;
                } elseif ($from === 'Valid' && $to === 'Active') {
                    $toActive++;
                } elseif ($from === 'Active' && $to === 'Valid') {
                    $toValid++;
                } else {
                    $otherStatus++;
                }
            }

            if (array_key_exists('connectivity_report', $fields)) {
                $connectivity++;
            }

            if (array_key_exists('score', $fields)) {
                $score++;
            }

            if (array_key_exists('total_stake', $fields)) {
                $stake++;
            }
        }

        $highlights = [];
        if ($toInvalid > 0) {
            $highlights[] = $toInvalid . ' moved to Invalid';
        }
        if ($toActive > 0) {
            $highlights[] = $toActive . ' moved to Active';
        }
        if ($toValid > 0) {
            $highlights[] = $toValid . ' moved to Valid';
        }
        if ($otherStatus > 0) {
            $highlights[] = $otherStatus . ' other status changes';
        }
        if ($connectivity > 0) {
            $highlights[] = $connectivity . ' connectivity changes';
        }
        if ($score > 0) {
            $highlights[] = $score . ' score changes';
        }
        if ($stake > 0) {
            $highlights[] = $stake . ' stake changes';
        }

        $lines = [
            'DMD validator update',
            count($changes) . ' validators changed',
            '* individually followed',
        ];

        if ($highlights !== []) {
            $lines[] = '';
            $lines[] = 'Highlights';
            foreach ($highlights as $highlight) {
                $lines[] = '- ' . $highlight;
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @param  array<string, int|string|bool>  $highlightedAddresses
     * @return array<int, array<int, string>>
     */
    private function groupedPoolChangeSections(array $changes, array $highlightedAddresses): array
    {
        $toInvalid = [];
        $validToActive = [];
        $activeToValid = [];
        $otherStatus = [];
        $connectivity = [];
        $score = [];
        $stake = [];

        foreach ($changes as $change) {
            $highlighted = array_key_exists($change['staking_address'], $highlightedAddresses);
            $addressLine = $this->addressOnlyLine($change['staking_address'], $highlighted);
            $fields = $change['fields'];

            if (array_key_exists('frontend_status', $fields)) {
                $from = $fields['frontend_status']['from'];
                $to = $fields['frontend_status']['to'];

                if ($to === 'Invalid') {
                    $toInvalid[] = $addressLine;
                } elseif ($from === 'Valid' && $to === 'Active') {
                    $validToActive[] = $addressLine;
                } elseif ($from === 'Active' && $to === 'Valid') {
                    $activeToValid[] = $addressLine;
                } else {
                    $otherStatus[] = $this->summarizeStatusChange($change, $highlighted);
                }
            }

            if (array_key_exists('connectivity_report', $fields)) {
                $connectivity[] = $this->summarizeConnectivityChange($change, $highlighted);
            }

            if (array_key_exists('score', $fields)) {
                $score[] = $this->summarizeScoreChange($change, $highlighted);
            }

            if (array_key_exists('total_stake', $fields)) {
                $stake[] = $this->summarizeStakeChange($change, $highlighted);
            }
        }

        $sections = [];

        if ($toInvalid !== []) {
            $sections[] = array_merge(['Status: -> Invalid'], $toInvalid);
        }
        if ($validToActive !== []) {
            $sections[] = array_merge(['Status: Valid -> Active'], $validToActive);
        }
        if ($activeToValid !== []) {
            $sections[] = array_merge(['Status: Active -> Valid'], $activeToValid);
        }
        if ($otherStatus !== []) {
            $sections[] = array_merge(['Other status changes'], $otherStatus);
        }
        if ($connectivity !== []) {
            $sections[] = array_merge(['Connectivity'], $connectivity);
        }
        if ($score !== []) {
            $sections[] = array_merge(['Score changes'], $score);
        }
        if ($stake !== []) {
            $sections[] = array_merge(['Stake changes'], $stake);
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function addressOnlyLine(string $stakingAddress, bool $highlighted): string
    {
        $prefix = $highlighted ? '* ' : '  ';

        return $prefix . $this->truncateAddress($stakingAddress);
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function summarizeStatusChange(array $change, bool $highlighted): string
    {
        $values = $change['fields']['frontend_status'];

        return $this->addressOnlyLine($change['staking_address'], $highlighted)
            . ': '
            . $values['from']
            . ' -> '
            . $values['to'];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function summarizeConnectivityChange(array $change, bool $highlighted): string
    {
        $values = $change['fields']['connectivity_report'];

        return $this->addressOnlyLine($change['staking_address'], $highlighted)
            . ': '
            . $values['from']
            . ' -> '
            . $values['to'];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function summarizeScoreChange(array $change, bool $highlighted): string
    {
        $values = $change['fields']['score'];

        return $this->addressOnlyLine($change['staking_address'], $highlighted)
            . ': '
            . $values['from']
            . ' -> '
            . $values['to'];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function summarizeStakeChange(array $change, bool $highlighted): string
    {
        $values = $change['fields']['total_stake'];

        return $this->addressOnlyLine($change['staking_address'], $highlighted)
            . ': '
            . $this->formatDmdAmount($values['from'])
            . ' -> '
            . $this->formatDmdAmount($values['to']);
    }

    private function messageDelay(int $dispatchIndex): \DateTimeInterface
    {
        return now()->addSeconds((int) floor($dispatchIndex / 10));
    }

    private function formatUnixTimestamp(int $timestamp): string
    {
        return now()->setTimestamp($timestamp)->utc()->toDateTimeString() . ' UTC';
    }

    private function epochFieldLines(array $fields): array
    {
        $labels = [
            'keygen_round' => 'Keygen round',
            'staking_epoch_start_time' => 'Epoch start time',
            'staking_epoch_start_block' => 'Epoch start block',
            'are_stake_and_withdraw_allowed' => 'Stake/withdraw allowed',
            'staking_fixed_epoch_end_time' => 'Epoch end time',
            'staking_fixed_epoch_duration' => 'Epoch duration',
            'staking_withdraw_disallow_period' => 'Withdraw disallow period',
            'delta_pot' => 'Delta pot',
            'reinsert_pot' => 'Reinsert pot',
        ];

        $lines = [];
        foreach ($fields as $field => $values) {
            $from = $values['from'];
            $to = $values['to'];

            if (in_array($field, ['staking_epoch_start_time', 'staking_fixed_epoch_end_time'], true)) {
                $from = $this->formatUnixTimestamp((int) $from);
                $to = $this->formatUnixTimestamp((int) $to);
            }

            if ($field === 'are_stake_and_withdraw_allowed') {
                $from = $from === '1' ? 'yes' : 'no';
                $to = $to === '1' ? 'yes' : 'no';
            }

            if (in_array($field, ['delta_pot', 'reinsert_pot'], true)) {
                $from = $this->formatDmdAmount($from);
                $to = $this->formatDmdAmount($to);
            }

            $lines[] = ($labels[$field] ?? $field) . ': ' . $from . ' -> ' . $to;
        }

        return $lines;
    }

    private function formatTokenAmount(string $value, int $decimals): string
    {
        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        $digits = $digits === '' ? '0' : $digits;

        if (strlen($digits) <= $decimals) {
            $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);
        }

        $integer = substr($digits, 0, -$decimals);
        $fraction = rtrim(substr($digits, -$decimals), '0');

        $formatted = $fraction === '' ? $integer : $integer . '.' . $fraction;

        return $negative ? '-' . $formatted : $formatted;
    }

    private function formatDmdAmount(string $value): string
    {
        return $this->roundFormattedAmount($this->formatTokenAmount($value, 18), 4) . ' DMD';
    }

    private function truncateAddress(string $address): string
    {
        return Str::substr($address, 0, 6) . '...' . Str::substr($address, -6);
    }

    private function roundFormattedAmount(string $value, int $precision): string
    {
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        $integer = $integer === '' ? '0' : $integer;
        $fraction = preg_replace('/\D/', '', $fraction) ?? '';
        $fraction = str_pad($fraction, $precision + 1, '0');

        $roundedFraction = substr($fraction, 0, $precision);
        $roundDigit = (int) $fraction[$precision];

        if ($roundDigit >= 5) {
            [$integer, $roundedFraction] = $this->incrementRoundedParts($integer, $roundedFraction);
        }

        return ($negative ? '-' : '') . $integer . '.' . $roundedFraction;
    }

    private function incrementRoundedParts(string $integer, string $fraction): array
    {
        if ($fraction !== '') {
            $digits = str_split($fraction);

            for ($index = count($digits) - 1; $index >= 0; $index--) {
                if ($digits[$index] !== '9') {
                    $digits[$index] = (string) ((int) $digits[$index] + 1);

                    return [$integer, implode('', $digits)];
                }

                $digits[$index] = '0';
            }
        }

        $integerDigits = str_split($integer);
        for ($index = count($integerDigits) - 1; $index >= 0; $index--) {
            if ($integerDigits[$index] !== '9') {
                $integerDigits[$index] = (string) ((int) $integerDigits[$index] + 1);

                return [implode('', $integerDigits), $fraction === '' ? '' : str_repeat('0', strlen($fraction))];
            }

            $integerDigits[$index] = '0';
        }

        return ['1' . implode('', $integerDigits), $fraction === '' ? '' : str_repeat('0', strlen($fraction))];
    }
}
