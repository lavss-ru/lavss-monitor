<?php

namespace App\Services;

use App\Models\Event;
use App\Models\LocalDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class LocalDeviceMonitoringService
{
    public function __construct(private LocalDeviceHealthCheckService $healthCheck, private MaxNotifier $notifier) {}

    /**
     * Serialize checks and edits per device. Persist factual state before attempting MAX.
     * Batch callers recheck effective enabled state here, avoiding stale batch selections.
     */
    public function monitor(LocalDevice $device, bool $diagnostic = false): array
    {
        $outcome = DB::transaction(function () use ($device, $diagnostic) {
            $device = LocalDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $enabled = $device->enabled && $device->location->enabled;
            if (! $enabled && ! $diagnostic) {
                return ['status' => $device->status, 'event_created' => false, 'skipped' => true];
            }
            try {
                $result = $this->healthCheck->check($device);
            } catch (Throwable $error) {
                // An unmeasured interval must not confirm a continuous TCP failure.
                $device->update(['status' => 'unknown', 'last_checked_at' => null, 'last_response_ms' => null,
                    'failure_started_at' => $device->incident_confirmed_at ? $device->failure_started_at : null]);
                return $error;
            }
            $down = $recovery = false;
            if ($enabled) {
                if ($device->status === 'offline') {
                    // Never publish an obsolete recovery while the target is down again.
                    $device->recovery_pending_at = null;
                    $device->failure_started_at ??= $device->last_checked_at;
                    if ($device->incident_confirmed_at === null
                        && $device->last_checked_at->greaterThanOrEqualTo($device->failure_started_at->copy()->addSeconds(120))) {
                        $device->incident_confirmed_at = $device->last_checked_at;
                        $down = true;
                    }
                } else {
                    $recovery = $device->incident_confirmed_at !== null;
                    if ($recovery) {
                        $device->recovery_pending_at = $device->last_checked_at;
                        $device->resolveWarnings();
                    }
                    $device->failure_started_at = null;
                    $device->incident_confirmed_at = null;
                    $device->incident_notified_at = null;
                }
                $device->save();
                if ($down || $recovery) {
                    $state = $down ? 'недоступно' : 'восстановлено';
                    Event::create([
                        'type' => 'local_device', 'source_id' => $device->id,
                        'severity' => $down ? 'warning' : 'info',
                        'title' => Str::limit("Локальное устройство {$device->name} {$state}", 255, ''),
                        'message' => "Устройство: {$device->name}. Площадка: {$device->location->name}. TCP: {$device->endpoint()}. "
                            .($down ? 'Сбой подтверждён после 2 минут недоступности.' : 'TCP соединение снова устанавливается.'),
                        'occurred_at' => $device->last_checked_at,
                    ]);
                }
            }
            return $result + ['event_created' => $down || $recovery, 'skipped' => false];
        });
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
        if (! $outcome['skipped']) {
            $this->notify($device->id);
        }
        return $outcome;
    }

    private function notify(int $id): void
    {
        try {
            // No transaction retry: external delivery cannot be rolled back.
            DB::transaction(function () use ($id) {
                $device = LocalDevice::whereKey($id)->lockForUpdate()->first();
                if (! $device || ! $device->enabled || ! $device->location->enabled) {
                    return;
                }
                if ($device->status === 'offline' && $device->incident_confirmed_at !== null && $device->incident_notified_at === null) {
                    if ($this->notifier->sendLocalDevice($device, false)) {
                        $device->update(['incident_notified_at' => now()]);
                    }
                } elseif ($device->status === 'online' && $device->recovery_pending_at !== null) {
                    if ($this->notifier->sendLocalDevice($device, true)) {
                        $device->update(['recovery_pending_at' => null]);
                    }
                }
            });
        } catch (Throwable $error) {
            report($error);
        }
    }

    public function checkAll(): array
    {
        $checked = $errors = 0;
        foreach (LocalDevice::monitored()->orderBy('id')->get() as $device) {
            try {
                $result = $this->monitor($device);
                $checked += $result['skipped'] ? 0 : 1;
            } catch (Throwable $error) {
                report($error);
                $errors++;
            }
        }
        return ['checked' => $checked, 'errors' => $errors];
    }
}
