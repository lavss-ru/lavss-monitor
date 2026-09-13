<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Vps;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class VpsMonitoringService
{
    public function __construct(
        private VpsHealthCheckService $healthCheck,
        private MaxNotifier $notifier,
        private MonitorCheckRecorder $history,
    ) {}

    public function monitor(Vps $vps, string $origin, ?NotificationPolicyService $policy = null): array
    {
        $policy ??= NotificationPolicyService::load();

        return $this->history->observe('vps', $vps->id, $origin,
            fn ($check) => $this->performMonitor($vps, $check, $policy));
    }

    private function performMonitor(Vps $vps, \Closure $check, NotificationPolicyService $policy): array
    {
        $outcome = DB::transaction(function () use ($vps, $check, $policy) {
            $vps = Vps::whereKey($vps->id)->lockForUpdate()->firstOrFail();
            $previous = $vps->status;
            // Pre-3.6 offline state has no delivery evidence. Preserve its Event transition
            // semantics, but never infer that MAX received a red notification.
            $legacy = $previous === 'offline' && $vps->failure_started_at === null;
            try {
                $result = $check(fn () => $this->healthCheck->check($vps));
            } catch (Throwable $error) {
                $vps->update(['status' => 'unknown', 'last_checked_at' => null, 'last_response_ms' => null,
                    'failure_started_at' => $vps->incident_confirmed_at ? $vps->failure_started_at : null]);

                return $error;
            }
            $down = $recovery = false;
            if ($vps->enabled) {
                if ($vps->status === 'offline') {
                    $vps->recovery_pending_at = null;
                    $vps->failure_started_at ??= $vps->last_checked_at;
                    if ($vps->incident_confirmed_at === null
                        && $vps->last_checked_at->greaterThanOrEqualTo($vps->failure_started_at->copy()->addSeconds($policy->delay('vps')))) {
                        $vps->incident_confirmed_at = $vps->last_checked_at;
                        $down = ! $legacy;
                    }
                } elseif ($vps->status === 'online') {
                    $recovery = $vps->incident_confirmed_at !== null || $legacy;
                    if ($recovery) {
                        $vps->recovery_pending_at = $vps->incident_notified_at !== null && $policy->recoveryEnabled('vps')
                            ? $vps->last_checked_at : null;
                        Event::where('type', 'vps')->where('source_id', $vps->id)->where('severity', 'warning')
                            ->whereNull('resolved_at')->update(['resolved_at' => now()]);
                    }
                    $vps->failure_started_at = $vps->incident_confirmed_at = $vps->incident_notified_at = null;
                }
                $vps->save();
                if ($down || $recovery) {
                    $state = $down ? 'недоступен' : 'восстановлен';
                    $ms = $result['response_ms'] ?? null;
                    Event::create([
                        'type' => 'vps', 'source_id' => $vps->id, 'severity' => $down ? 'warning' : 'info',
                        'title' => Str::limit("VPS {$vps->name} {$state}", 255, ''),
                        'message' => "TCP connect к {$vps->ip_address}:{$vps->check_port} "
                            .($down ? 'не удался.' : 'снова успешен'.($ms !== null ? ", отклик {$ms} ms." : '.')),
                        'occurred_at' => $vps->last_checked_at,
                    ]);
                }
            }

            return $result + ['previous_status' => $previous, 'event_created' => $down || $recovery];
        });
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
        try {
            DB::transaction(function () use ($vps, $policy) {
                $vps = Vps::whereKey($vps->id)->lockForUpdate()->first();
                if (! $vps || ! $vps->enabled) {
                    return;
                }
                if ($vps->status === 'offline' && $vps->incident_confirmed_at !== null && $vps->incident_notified_at === null) {
                    if ($policy->decision('vps', false) === 'deliver' && $this->notifier->sendDown($vps, $policy)) {
                        $vps->update(['incident_notified_at' => now()]);
                    }
                } elseif ($vps->status === 'online' && $vps->recovery_pending_at !== null) {
                    if (! $policy->recoveryEnabled('vps')
                        || ($policy->decision('vps', true) === 'deliver' && $this->notifier->sendRecovery($vps, $policy))) {
                        $vps->update(['recovery_pending_at' => null]);
                    }
                }
            });
        } catch (Throwable $error) {
            report($error);
        }
        $vps->refresh();

        return $outcome;
    }
}
