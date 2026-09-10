<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Website;
use App\Models\WebsiteAggregateState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebsiteMonitoringService
{
    public function __construct(
        private WebsiteHealthCheckService $healthCheck,
    ) {
    }

    /**
     * @return array{enabled: bool, status: string, http_status: int|null, response_ms: int, previous_status: string|null, event_created: bool}
     */
    public function monitor(Website $website): array
    {
        $outcome = DB::transaction(function () use ($website) {
            WebsiteAggregateState::lock();
            // Reload after locking: route-bound and batch models may be stale.
            $website = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();
            $previousStatus = $website->status;
            try {
                $result = $this->healthCheck->check($website);
            } catch (\Throwable $error) {
                // Persist uncertainty so a later single-check evaluation cannot use stale online health.
                $website->update(['status' => 'unknown', 'last_checked_at' => null,
                    'last_http_status' => null, 'last_response_ms' => null]);
                return $error;
            }
            $new = $website->status;
            $down = $recovery = false;

            if ($website->enabled) {
                if ($new === 'offline') {
                    $website->failure_started_at ??= $website->last_checked_at;
                    if ($website->incident_confirmed_at === null
                        && $website->last_checked_at->greaterThanOrEqualTo($website->failure_started_at->copy()->addMinutes(10))) {
                        $website->incident_confirmed_at = $website->last_checked_at;
                        $down = true;
                    }
                } elseif ($new === 'online') {
                    $recovery = $website->incident_confirmed_at !== null;
                    $website->failure_started_at = null;
                    $website->incident_confirmed_at = null;
                    $website->incident_notified_at = null;
                }
                $website->save();
            }

            if ($down || $recovery) {
                $http = $website->last_http_status !== null
                    ? 'HTTP '.$website->last_http_status
                    : 'HTTP-ответ не получен';
                $state = $down ? 'недоступен' : 'восстановлен';

                Event::create([
                    'type' => 'website',
                    'source_id' => $website->id,
                    'severity' => $down ? 'warning' : 'info',
                    'title' => Str::limit("Сайт {$website->name} {$state}", 255, ''),
                    'message' => "Сайт: {$website->name}. URL: {$website->url}. {$http}. Длительность проверки: {$website->last_response_ms} ms.",
                    'occurred_at' => $website->last_checked_at,
                    'resolved_at' => null,
                ]);
            }

            return [
                'enabled' => $website->enabled,
                'status' => $new,
                'http_status' => $result['http_status'],
                'response_ms' => $result['response_ms'],
                'previous_status' => $previousStatus,
                'event_created' => $down || $recovery,
            ];
        });
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }
        return $outcome;
    }
}
