<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class LocationMonitoringService
{
    public function __construct(private LocationHealthCheckService $healthCheck, private MaxNotifier $notifier, private MonitorCheckRecorder $history, private WireGuardDiagnosticsService $wireguard) {}

    /**
     * Serialize checks and edits per location. Persist factual state before attempting MAX.
     * Batch callers recheck effective enabled state here, avoiding stale batch selections.
     */
    public function monitor(Location $location, string $origin, ?NotificationPolicyService $policy = null): array
    {
        $policy ??= NotificationPolicyService::load();

        return $this->history->observe('location', $location->id, $origin,
            fn ($check) => $this->performMonitor($location, $check, $policy));
    }

    private function performMonitor(Location $location, \Closure $check, NotificationPolicyService $policy): array
    {
        $outcome = DB::transaction(function () use ($location, $check, $policy) {
            $location = Location::whereKey($location->id)->lockForUpdate()->firstOrFail();
            $enabled = $location->monitored();
            if (! $enabled) {
                return ['status' => $location->status, 'event_created' => false, 'skipped' => true];
            }
            try {
                $result = $check(fn () => $this->healthCheck->check($location));
            } catch (Throwable $error) {
                // An unmeasured interval must not confirm a continuous TCP failure.
                $location->update(['wireguard_diagnostic_state' => 'unknown', 'wireguard_last_handshake_at' => null,
                    'wireguard_rx_bytes' => null, 'wireguard_tx_bytes' => null, 'status' => 'unknown', 'last_checked_at' => null, 'last_response_ms' => null,
                    'failure_started_at' => $location->incident_confirmed_at ? $location->failure_started_at : null]);

                return $error;
            }
            $location->fill($this->wireguard->inspect($location));
            $down = $recovery = false;
            if ($enabled) {
                if ($location->status === 'offline') {
                    // Never publish an obsolete recovery while the target is down again.
                    $location->recovery_pending_at = null;
                    $location->failure_started_at ??= $location->last_checked_at;
                    if ($location->incident_confirmed_at === null
                        && $location->last_checked_at->greaterThanOrEqualTo($location->failure_started_at->copy()->addSeconds($policy->delay('location')))) {
                        $location->incident_confirmed_at = $location->last_checked_at;
                        $down = true;
                    }
                } elseif ($location->status === 'online') {
                    $recovery = $location->incident_confirmed_at !== null;
                    if ($recovery) {
                        $location->recovery_pending_at = $location->incident_notified_at !== null && $policy->recoveryEnabled('location')
                            ? $location->last_checked_at : null;
                        $location->resolveWarnings();
                    }
                    $location->failure_started_at = null;
                    $location->incident_confirmed_at = null;
                    $location->incident_notified_at = null;
                }
                if ($location->status === 'unknown' && $location->incident_confirmed_at === null) {
                    $location->failure_started_at = null;
                }
                $location->save();
                if ($down || $recovery) {
                    $state = $down ? 'недоступна' : 'восстановлена';
                    Event::create([
                        'type' => 'location', 'source_id' => $location->id,
                        'severity' => $down ? 'warning' : 'info',
                        'title' => Str::limit("Площадка {$location->name} {$state}", 255, ''),
                        'message' => "Площадка: {$location->name}. Подключение: {$location->connection_type}. TCP: {$location->endpoint()}. "
                            .($down ? 'Сбой подтверждён после заданного интервала недоступности.' : 'TCP соединение снова устанавливается.'),
                        'occurred_at' => $location->last_checked_at,
                    ]);
                }
            }

            return $result + ['event_created' => $down || $recovery, 'skipped' => false];
        });
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
        if (! $outcome['skipped']) {
            $this->notify($location->id, $policy);
        }

        return $outcome;
    }

    private function notify(int $id, NotificationPolicyService $policy): void
    {
        try {
            // No transaction retry: external delivery cannot be rolled back.
            DB::transaction(function () use ($id, $policy) {
                $location = Location::whereKey($id)->lockForUpdate()->first();
                if (! $location || ! $location->monitored()) {
                    return;
                }
                if ($location->status === 'offline' && $location->incident_confirmed_at !== null && $location->incident_notified_at === null) {
                    if ($policy->decision('location', false) === 'deliver' && $this->notifier->sendLocation($location, false, $policy)) {
                        $location->update(['incident_notified_at' => now()]);
                    }
                } elseif ($location->status === 'online' && $location->recovery_pending_at !== null) {
                    if (! $policy->recoveryEnabled('location')
                        || ($policy->decision('location', true) === 'deliver' && $this->notifier->sendLocation($location, true, $policy))) {
                        $location->update(['recovery_pending_at' => null]);
                    }
                }
            });
        } catch (Throwable $error) {
            report($error);
        }
    }
}
