<?php

namespace App\Services;

use App\Models\Location;
use App\Models\MonitorCheck;
use App\Models\ProxmoxConnection;
use Illuminate\Support\Facades\DB;

class ProxmoxSyncService
{
    public function __construct(private ProxmoxApiClient $api, private ProxmoxMonitoringService $monitoring) {}

    public function run(ProxmoxConnection $connection, bool $sync = true, string $origin = 'manual'): array
    {
        if (! in_array($origin, MonitorCheck::ORIGINS, true)) {
            throw new \InvalidArgumentException('Invalid check origin.');
        }
        $policy = NotificationPolicyService::load();
        $connection = $connection->fresh(['location']);
        if (! $connection) {
            return ['status' => 'unknown', 'code' => 'superseded', 'skipped' => true];
        }
        $reason = $connection->unavailableReason();
        $revision = $connection->revision;
        $started = hrtime(true);
        $inventory = null;
        $version = null;
        $code = $reason;
        if (! $reason) {
            try {
                if ($sync) {
                    $inventory = $this->api->inventory($connection);
                } else {
                    $version = $this->api->version($connection);
                }
            } catch (ProxmoxApiException $error) {
                $code = $error->safeCode;
            } catch (\Throwable) {
                $code = 'internal';
            }
        }
        $elapsed = (int) round((hrtime(true) - $started) / 1_000_000);
        try {
            // All network work is finished. Revision rejects overlapping fetches and edits.
            $result = DB::transaction(function () use ($connection, $revision, $reason, $code, $inventory, $version, $elapsed, $sync, $origin, $policy) {
                $current = ProxmoxConnection::whereKey($connection->id)->lockForUpdate()->first();
                if (! $current || $current->revision !== $revision) {
                    return ['status' => 'unknown', 'code' => 'superseded', 'skipped' => true];
                }
                $current->setRelation('location', Location::whereKey($current->location_id)->sharedLock()->firstOrFail());
                $blocked = $current->unavailableReason();
                $error = $blocked ?? $code;
                $status = $error === null ? 'online'
                    : (in_array($error, ['network', 'http'], true) ? 'offline' : 'unknown');
                $changes = ['status' => $status, 'last_error_code' => $error,
                    'last_response_ms' => $error === null ? $elapsed : null, 'revision' => $revision + 1];
                if (! $blocked && ! $reason) {
                    $changes['last_checked_at'] = now();
                }
                if ($error === null && $version !== null) {
                    $changes['version'] = $version;
                }
                if ($error === null && $inventory !== null) {
                    $seen = now();
                    $current->nodes()->update(['stale' => true, 'status' => 'unknown']);
                    $current->guests()->update(['stale' => true, 'status' => 'unknown']);
                    $nodeIds = [];
                    foreach ($inventory['nodes'] as $name => $data) {
                        $node = $current->nodes()->updateOrCreate(['node_name' => $name],
                            $data + ['stale' => false, 'last_seen_at' => $seen]);
                        $nodeIds[$name] = $node->id;
                    }
                    foreach ($inventory['guests'] as $data) {
                        $data['proxmox_node_id'] = $nodeIds[$data['node_name']];
                        unset($data['node_name']);
                        $current->guests()->updateOrCreate(
                            ['guest_type' => $data['guest_type'], 'vmid' => $data['vmid']],
                            $data + ['stale' => false, 'last_seen_at' => $seen]);
                    }
                    $changes['last_synced_at'] = $seen;
                }
                // A successful version-only test must not make an old inventory factual again.
                if ($error !== null || ($inventory === null && $current->status !== 'online')) {
                    $changes['last_synced_at'] = null;
                }
                $current->update($changes);
                $this->monitoring->evaluate($current, $sync, ! $blocked && ! $reason, $origin, $policy);

                return ['status' => $status, 'code' => $error, 'skipped' => $blocked !== null || $reason !== null];
            });
            if (! $result['skipped']) {
                $this->monitoring->notify($connection->id, $revision + 1, $sync, $policy);
            }

            return $result;
        } catch (\Throwable) {
            // Raw DB/transport exceptions must not enter logs or response context.
            try {
                DB::transaction(function () use ($connection, $revision) {
                    $current = ProxmoxConnection::whereKey($connection->id)->lockForUpdate()->first();
                    if (! $current || $current->revision !== $revision) {
                        return;
                    }
                    ProxmoxConnection::whereKey($current->id)->update([
                        'status' => 'unknown', 'last_error_code' => 'internal', 'last_response_ms' => null,
                        'last_synced_at' => null, 'revision' => $revision + 1,
                        'failure_started_at' => $current->incident_confirmed_at ? $current->failure_started_at : null,
                    ]);
                    $current->nodes()->whereNull('incident_confirmed_at')->update(['failure_started_at' => null]);
                    $current->guests()->whereNull('incident_confirmed_at')->update(['failure_started_at' => null]);
                });
            } catch (\Throwable) {
                // Database unavailable: no trustworthy write is possible.
            }

            return ['status' => 'unknown', 'code' => 'internal', 'skipped' => false];
        }
    }
}
