<?php

namespace App\Services;

use App\Models\Vps;

class VpsHealthCheckService
{
    /**
     * Timeout in seconds for TCP connect attempt.
     */
    private float $timeout = 3.0;

    /**
     * Perform a TCP health-check against the given VPS.
     *
     * Uses stream_socket_client — no shell commands involved.
     * Updates the VPS model in the database with the result.
     *
     * @return array{status: string, response_ms: int|null}
     */
    public function check(Vps $vps): array
    {
        $address = $this->buildAddress($vps->ip_address, $vps->check_port);
        $startTime = microtime(true);

        $errno  = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        $elapsed = microtime(true) - $startTime;
        $responseMs = (int) round($elapsed * 1000);

        if ($socket !== false) {
            fclose($socket);
            $result = [
                'status'      => 'online',
                'response_ms' => $responseMs,
            ];
        } else {
            $result = [
                'status'      => 'offline',
                'response_ms' => $responseMs,
            ];
        }

        $vps->update([
            'status'          => $result['status'],
            'last_checked_at' => now(),
            'last_response_ms'=> $result['response_ms'],
        ]);

        return $result;
    }

    /**
     * Build the TCP address string for stream_socket_client.
     *
     * IPv4: tcp://192.168.1.1:22
     * IPv6: tcp://[2001:db8::1]:22  (brackets required by RFC 3986)
     *
     * Detection uses filter_var — reliable, no shell commands.
     */
    public function buildAddress(string $ip, int $port): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return 'tcp://[' . $ip . ']:' . $port;
        }

        return 'tcp://' . $ip . ':' . $port;
    }
}
