<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Location;
use App\Models\ProxmoxConnection;
use App\Models\ProxmoxGuest;
use App\Models\ProxmoxNode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProxmoxMonitoringService
{
    public function __construct(private MonitorCheckRecorder $history, private MaxNotifier $notifier) {}

    public function locationReady(ProxmoxConnection $connection): bool
    {
        return ! $connection->unavailableReason()
            && (! $connection->location->monitored() || $connection->location->status === 'online');
    }

    public function connectionReady(ProxmoxConnection $connection): bool
    {
        return $this->locationReady($connection) && $connection->status === 'online'
            && $connection->incident_confirmed_at === null && $connection->last_synced_at !== null;
    }

    public function state(Model $monitor, ProxmoxConnection $connection): string
    {
        if (! $this->locationReady($connection)) {
            return 'unknown';
        }
        if ($monitor instanceof ProxmoxConnection) {
            if ($connection->status === 'online' && $connection->last_synced_at === null
                && ($connection->incident_confirmed_at !== null || $connection->recovery_pending_at !== null)) {
                return 'unknown'; // Recovery requires a complete factual inventory refresh.
            }

            return $connection->status;
        }
        if (! $this->connectionReady($connection) || $monitor->stale) {
            return 'unknown';
        }
        if ($monitor instanceof ProxmoxNode) {
            return $monitor->status;
        }
        $node = $monitor->node;
        if (! $node || $node->stale || $node->status !== 'online' || $node->incident_confirmed_at !== null
            || ! in_array($monitor->status, ['running', 'stopped', 'paused'], true)) {
            return 'unknown';
        }

        return $monitor->status === $monitor->expected_status ? 'online' : 'offline';
    }

    public function enabled(Model $monitor): bool
    {
        return $monitor->monitoring_enabled && (! $monitor instanceof ProxmoxGuest || $monitor->expected_status !== 'ignore');
    }

    /** Called inside the accepted inventory transaction, under the connection lock. */
    public function evaluate(ProxmoxConnection $connection, bool $sync, bool $measured, string $origin, NotificationPolicyService $policy): void
    {
        $this->transition($connection, $connection, 'proxmox_connection', $measured, $origin, $policy);
        if (! $sync) {
            if (! $this->connectionReady($connection)) {
                $connection->nodes()->whereNull('incident_confirmed_at')->update(['failure_started_at' => null]);
                $connection->guests()->whereNull('incident_confirmed_at')->update(['failure_started_at' => null]);
            }

            return;
        }
        foreach ($connection->nodes()->orderBy('id')->lockForUpdate()->get() as $node) {
            $this->transition($node, $connection, 'proxmox_node', $measured && $connection->status === 'online', $origin, $policy);
        }
        foreach ($connection->guests()->with('node')->orderBy('id')->lockForUpdate()->get() as $guest) {
            $this->transition($guest, $connection, 'proxmox_guest', $measured && $connection->status === 'online', $origin, $policy);
        }
    }

    private function transition(Model $monitor, ProxmoxConnection $connection, string $type, bool $measured, string $origin, NotificationPolicyService $policy): void
    {
        $state = $this->enabled($monitor) ? $this->state($monitor, $connection) : 'unknown';
        $down = $recovery = false;
        if ($state === 'offline') {
            $monitor->recovery_pending_at = null;
            $monitor->failure_started_at ??= now();
            if ($monitor->incident_confirmed_at === null && now()->greaterThanOrEqualTo($monitor->failure_started_at->copy()->addSeconds($policy->delay($type)))) {
                $monitor->incident_confirmed_at = now();
                $down = true;
            }
        } elseif ($state === 'online') {
            $recovery = $monitor->incident_confirmed_at !== null;
            if ($recovery) {
                $monitor->recovery_pending_at = $monitor->incident_notified_at !== null && $policy->recoveryEnabled($type) ? now() : null;
                Event::where('type', $type)->where('source_id', $monitor->id)->where('severity', 'warning')
                    ->whereNull('resolved_at')->update(['resolved_at' => now()]);
            }
            $monitor->failure_started_at = $monitor->incident_confirmed_at = $monitor->incident_notified_at = null;
        } elseif ($monitor->incident_confirmed_at === null) {
            $monitor->failure_started_at = null;
        }
        if ($monitor->isDirty()) {
            $monitor->save();
        }
        if ($measured && $this->enabled($monitor)) {
            $this->history->record($type, $monitor->id, $origin, ['status' => $monitor instanceof ProxmoxConnection ? $connection->status : $state, 'checked_at' => now(),
                'response_ms' => $monitor instanceof ProxmoxConnection ? $monitor->last_response_ms : null, 'http_status' => null]);
        }
        if ($down || $recovery) {
            Event::create(['type' => $type, 'source_id' => $monitor->id, 'severity' => $down ? 'warning' : 'info',
                'title' => Str::limit($this->title($type, $recovery).' · '.($monitor->name ?? $monitor->node_name ?? $monitor->vmid), 255, ''),
                'message' => $this->message($monitor, $connection), 'occurred_at' => now()]);
        }
    }

    public function title(string $type, bool $recovery): string
    {
        return match ($type) {
            'proxmox_connection' => $recovery ? 'Proxmox снова доступен' : 'Proxmox недоступен',
            'proxmox_node' => $recovery ? 'Узел Proxmox снова доступен' : 'Узел Proxmox недоступен',
            default => $recovery ? 'VM/LXC в ожидаемом состоянии' : 'VM/LXC не в ожидаемом состоянии',
        };
    }

    public function message(Model $monitor, ProxmoxConnection $connection): string
    {
        $lines = ["Подключение: {$connection->name}"];
        if ($monitor instanceof ProxmoxNode) {
            $lines[] = "Узел: {$monitor->node_name}";
        } elseif ($monitor instanceof ProxmoxGuest) {
            $lines[] = 'Узел: '.($monitor->node?->node_name ?? 'unknown');
            $lines[] = ($monitor->guest_type === 'qemu' ? 'VM' : 'LXC')." {$monitor->vmid}: {$monitor->name}";
            $lines[] = "Ожидается: {$monitor->expected_status}; фактически: {$monitor->status}";
        }

        return implode("\n", $lines);
    }

    /** Persisted state first, delivery second; same lock order as sync and policy edits. */
    public function notify(int $connectionId, int $revision, bool $sync, NotificationPolicyService $policy): void
    {
        try {
            DB::transaction(function () use ($connectionId, $revision, $sync, $policy) {
                $connection = ProxmoxConnection::whereKey($connectionId)->lockForUpdate()->first();
                if (! $connection || $connection->revision !== $revision) {
                    return;
                }
                $connection->setRelation('location', Location::whereKey($connection->location_id)->sharedLock()->firstOrFail());
                $monitors = [[$connection, 'proxmox_connection']];
                if ($sync) {
                    foreach ($connection->nodes()->orderBy('id')->lockForUpdate()->get() as $node) {
                        $monitors[] = [$node, 'proxmox_node'];
                    }
                    foreach ($connection->guests()->with('node')->orderBy('id')->lockForUpdate()->get() as $guest) {
                        $monitors[] = [$guest, 'proxmox_guest'];
                    }
                }
                foreach ($monitors as [$monitor, $type]) {
                    if (! $this->enabled($monitor)) {
                        continue;
                    }
                    $state = $this->state($monitor, $connection);
                    $down = $state === 'offline' && $monitor->incident_confirmed_at !== null && $monitor->incident_notified_at === null;
                    $recovery = $state === 'online' && $monitor->recovery_pending_at !== null;
                    if ($recovery && ! $policy->recoveryEnabled($type)) {
                        $monitor->update(['recovery_pending_at' => null]);
                    } elseif (($down || $recovery) && $policy->decision($type, $recovery) === 'deliver') {
                        $text = ($recovery ? '🟢 ' : '🔴 ').$this->title($type, $recovery)."\n".$this->message($monitor, $connection)
                            ."\nВремя: ".now()->setTimezone($policy->timezone())->format('d.m.Y H:i:s T');
                        if ($this->notifier->sendProxmox($text, $type, $recovery, $policy)) {
                            $monitor->update($recovery ? ['recovery_pending_at' => null] : ['incident_notified_at' => now()]);
                        }
                    }
                }
            });
        } catch (\Throwable) {
            // No raw database or transport exception may disclose token material.
        }
    }
}
