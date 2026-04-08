<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlockchainRpcService
{
    public function ethCall(string $rpcUrl, string $to, string $data, string $block = 'latest'): ?string
    {
        $response = Http::post($rpcUrl, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'eth_call',
            'params' => [[
                'to' => $to,
                'data' => $data,
            ], $block],
        ]);

        if ($response->failed()) {
            Log::error("RPC Error: " . $response->body());
            return null;
        }

        return $response->json('result');
    }

    /**
     * Fetch logs with automatic hex conversion for block numbers.
     */
    public function getLogs(string $rpcUrl, string | array $addresses, int $fromBlock, int $toBlock, array $topics)
    {
        $response = Http::post($rpcUrl, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'eth_getLogs',
            'params' => [[
                             'address'   => $addresses,
                             'fromBlock' => '0x' . dechex($fromBlock),
                             'toBlock'   => '0x' . dechex($toBlock),
                             'topics'    => $topics
                         ]],
        ]);
        if ($response->failed()) {
            Log::error("RPC Error: " . $response->body());
            return null;
        }

        return $response->json('result');
    }

    /**
     * Get the current latest block height from the chain.
     */
    public function getLatestBlock(string $rpcUrl): int
    {
        $response = Http::post($rpcUrl, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'eth_blockNumber',
            'params' => [],
        ]);

        return hexdec($response->json('result'));
    }

    /**
     * Fetch a single block header by number.
     *
     * @return array<string, mixed>|null
     */
    public function getBlockByNumber(string $rpcUrl, int $blockNumber): ?array
    {
        $response = Http::post($rpcUrl, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'eth_getBlockByNumber',
            'params' => ['0x' . dechex($blockNumber), false],
        ]);

        if ($response->failed()) {
            Log::error("RPC Error: " . $response->body());

            return null;
        }

        $result = $response->json('result');

        return is_array($result) ? $result : null;
    }

    /**
     * Fetch block timestamps for a list of block numbers via JSON-RPC batch.
     *
     * @return array<int, int> [block_number => timestamp]
     */
    public function getBlockTimestampsByNumber(string $rpcUrl, array $blockNumbers): array
    {
        if (empty($blockNumbers)) {
            return [];
        }

        $payload = [];
        foreach ($blockNumbers as $blockNumber) {
            $payload[] = [
                'jsonrpc' => '2.0',
                'id' => (int) $blockNumber,
                'method' => 'eth_getBlockByNumber',
                'params' => ['0x' . dechex((int) $blockNumber), false],
            ];
        }

        $response = Http::post($rpcUrl, $payload);
        if ($response->failed()) {
            Log::error("RPC Error: " . $response->body());
            return [];
        }

        $data = $response->json();
        if (!is_array($data)) {
            Log::error('RPC Error: Invalid batch response');
            return [];
        }

        $timestamps = [];
        foreach ($data as $item) {
            $result = $item['result'] ?? null;
            $id = $item['id'] ?? null;
            if (!$result || $id === null) {
                continue;
            }

            $tsHex = $result['timestamp'] ?? null;
            if (!$tsHex) {
                continue;
            }

            $timestamps[(int) $id] = hexdec($tsHex);
        }

        return $timestamps;
    }
}
