<?php

namespace App\Services;

use App\Models\ProxmoxConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ProxmoxApiClient
{
    private function get(ProxmoxConnection $connection, string $path): mixed
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['Authorization' => 'PVEAPIToken='.$connection->api_user.'!'.$connection->api_token_id.'='.$connection->api_token_secret])
                ->timeout(config('proxmox.timeout', 10))->connectTimeout(config('proxmox.connect_timeout', 3))
                ->withOptions(['verify' => $connection->verify_tls, 'allow_redirects' => false])
                ->get($connection->baseUrl().$path);
            if (in_array($response->status(), [401, 403], true)) {
                throw new ProxmoxApiException('auth');
            }
            if (! $response->successful()) {
                throw new ProxmoxApiException('http');
            }
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($body) || ! array_key_exists('data', $body) || isset($body['errors'])) {
                throw new ProxmoxApiException('payload');
            }

            return $body['data'];
        } catch (ProxmoxApiException $error) {
            throw $error;
        } catch (ConnectionException) {
            throw new ProxmoxApiException('network');
        } catch (\JsonException) {
            throw new ProxmoxApiException('payload');
        } catch (\Throwable) {
            throw new ProxmoxApiException('internal');
        }
    }

    public function version(ProxmoxConnection $connection): string
    {
        $data = $this->get($connection, '/version');
        if (! is_array($data) || ! is_string($data['version'] ?? null)
            || ! preg_match('/^[a-zA-Z0-9.+:~_-]{1,100}$/D', $data['version'])) {
            throw new ProxmoxApiException('payload');
        }

        return $data['version'];
    }

    /** Two bulk endpoints; /nodes cross-check prevents accepting an empty/partial resource snapshot. */
    public function inventory(ProxmoxConnection $connection): array
    {
        $nodes = $this->get($connection, '/nodes');
        $resources = $this->get($connection, '/cluster/resources');
        if (! is_array($nodes) || ! array_is_list($nodes) || ! $nodes
            || ! is_array($resources) || ! array_is_list($resources)) {
            throw new ProxmoxApiException('payload');
        }
        $expected = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! $this->nodeName($node['node'] ?? null) || isset($expected[$node['node']])) {
                throw new ProxmoxApiException('payload');
            }
            $expected[$node['node']] = true;
        }
        $result = ['nodes' => [], 'guests' => []];
        foreach ($resources as $row) {
            if (! is_array($row) || ! is_string($row['type'] ?? null)) {
                throw new ProxmoxApiException('payload');
            }
            if (in_array($row['type'], ['storage', 'pool', 'sdn'], true)) {
                continue;
            }
            if (! in_array($row['type'], ['node', 'qemu', 'lxc'], true)
                || ! $this->nodeName($row['node'] ?? null) || ! isset($expected[$row['node']])
                || ! is_string($row['status'] ?? null) || $row['status'] === '') {
                throw new ProxmoxApiException('payload');
            }
            $metrics = [];
            foreach (['cpu' => 'cpu_usage', 'mem' => 'memory_used', 'maxmem' => 'memory_total',
                'maxcpu' => 'max_cpu', 'uptime' => 'uptime_seconds', 'disk' => 'disk_used', 'maxdisk' => 'disk_total'] as $source => $target) {
                $value = $row[$source] ?? null;
                if ($value !== null && (! is_numeric($value) || ! is_finite((float) $value)
                    || $value < 0 || ($source === 'cpu' ? $value > 1 : ($value >= PHP_INT_MAX || floor((float) $value) != $value))
                    || ($source === 'maxcpu' && $value > 2147483647))) {
                    throw new ProxmoxApiException('payload');
                }
                $metrics[$target] = $value === null ? null : ($source === 'cpu' ? (float) $value : (int) $value);
            }
            if ($row['type'] === 'node') {
                if (isset($result['nodes'][$row['node']])) {
                    throw new ProxmoxApiException('payload');
                }
                unset($metrics['disk_used'], $metrics['disk_total']);
                $result['nodes'][$row['node']] = $metrics + ['node_name' => $row['node'],
                    'status' => in_array($row['status'], ['online', 'offline'], true) ? $row['status'] : 'unknown'];
            } else {
                $vmid = $row['vmid'] ?? null;
                if ((! is_int($vmid) && ! is_string($vmid)) || filter_var($vmid, FILTER_VALIDATE_INT) === false || $vmid < 1 || $vmid > 2147483647
                    || (isset($row['name']) && (! is_string($row['name']) || mb_strlen($row['name']) > 255))
                    || (isset($row['template']) && ! in_array($row['template'], [0, 1, '0', '1', true, false], true))) {
                    throw new ProxmoxApiException('payload');
                }
                $key = $row['type'].':'.(int) $vmid;
                if (isset($result['guests'][$key])) {
                    throw new ProxmoxApiException('payload');
                }
                $result['guests'][$key] = $metrics + ['node_name' => $row['node'], 'guest_type' => $row['type'],
                    'vmid' => (int) $vmid, 'name' => $row['name'] ?? null, 'template' => (bool) ($row['template'] ?? false),
                    'status' => in_array($row['status'], ['running', 'stopped', 'paused'], true) ? $row['status'] : 'unknown'];
            }
        }
        $actual = array_keys($result['nodes']);
        $expected = array_keys($expected);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new ProxmoxApiException('payload');
        }

        return $result;
    }

    private function nodeName(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,252}$/D', $value);
    }
}
