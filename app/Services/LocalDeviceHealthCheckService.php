<?php

namespace App\Services;

use App\Models\LocalDevice;
use App\Rules\TcpHost;
use InvalidArgumentException;

class LocalDeviceHealthCheckService
{
    public const TIMEOUT = 3.0;

    public function __construct(private VpsHealthCheckService $addresses) {}

    /** @return array{status: string, response_ms: int|null} */
    public function check(LocalDevice $device): array
    {
        if (! TcpHost::valid($device->host) || $device->check_port < 1 || $device->check_port > 65535) {
            throw new InvalidArgumentException('Invalid local device TCP endpoint.');
        }
        // Reuse proven VPS IPv6 formatting without changing its monitoring path.
        $address = $this->addresses->buildAddress($device->host, $device->check_port);
        $start = hrtime(true);
        $socket = $this->connect($address);
        $ms = max(0, (int) round((hrtime(true) - $start) / 1_000_000));
        $online = $socket !== false;
        if ($online) {
            fclose($socket);
        }
        $result = ['status' => $online ? 'online' : 'offline', 'response_ms' => $online ? $ms : null];
        $device->update([
            'status' => $result['status'], 'last_checked_at' => now(),
            'last_response_ms' => $result['response_ms'],
        ]);
        return $result;
    }

    /** Small transport seam allows tests to exercise the real checker without networking. */
    protected function connect(string $address): mixed
    {
        return @stream_socket_client($address, $errno, $error, self::TIMEOUT, STREAM_CLIENT_CONNECT);
    }
}
