<?php

namespace App\Services;

use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Support\Facades\Process;
use Throwable;

/** Read only, and never request dump: it includes private/preshared keys. */
class WireGuardDiagnosticsService
{
    public function inspect(Location $location): array
    {
        $unknown = ['wireguard_diagnostic_state' => 'unknown', 'wireguard_last_handshake_at' => null,
            'wireguard_rx_bytes' => null, 'wireguard_tx_bytes' => null];
        if ($location->connection_type !== 'wireguard' || ! is_string($location->wireguard_interface)
            || ! preg_match('/^[A-Za-z0-9_.-]{1,15}$/D', $location->wireguard_interface)
            || str_starts_with($location->wireguard_interface, '-')) {
            return $unknown;
        }
        try {
            $handshakes = Process::timeout(2)->run(['wg', 'show', $location->wireguard_interface, 'latest-handshakes']);
            $transfer = Process::timeout(2)->run(['wg', 'show', $location->wireguard_interface, 'transfer']);
            if (! $handshakes->successful() || ! $transfer->successful()) {
                return $unknown;
            }
            $peers = $this->parse($handshakes->output(), 1);
            $bytes = $this->parse($transfer->output(), 2);
            $key = $location->wireguard_peer_public_key;
            if ($key === null && count($peers) === 1) {
                $key = array_key_first($peers);
            }
            if ($key === null || ! isset($peers[$key], $bytes[$key])) {
                return $unknown;
            }
            $timestamp = $peers[$key][0];
            if ($timestamp > now()->timestamp) {
                return $unknown;
            }

            return ['wireguard_diagnostic_state' => $timestamp === 0 ? 'never' : (now()->timestamp - $timestamp > 180 ? 'stale' : 'fresh'),
                'wireguard_last_handshake_at' => $timestamp ? Carbon::createFromTimestampUTC($timestamp) : null,
                'wireguard_rx_bytes' => $bytes[$key][0], 'wireguard_tx_bytes' => $bytes[$key][1]];
        } catch (Throwable) {
            // Permissions, absent binary/interface and invalid output are diagnostic failures only.
            return $unknown;
        }
    }

    private function parse(string $output, int $numbers): array
    {
        $rows = [];
        foreach (explode("\n", trim($output)) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            $key = array_shift($parts);
            if (count($parts) !== $numbers || strlen((string) base64_decode($key, true)) !== 32 || isset($rows[$key])) {
                throw new \UnexpectedValueException('Invalid diagnostic output');
            }
            foreach ($parts as $value) {
                if (! ctype_digit($value) || strlen($value) > 18) {
                    throw new \UnexpectedValueException('Invalid counter');
                }
            }
            $rows[$key] = array_map('intval', $parts);
        }

        return $rows;
    }
}
